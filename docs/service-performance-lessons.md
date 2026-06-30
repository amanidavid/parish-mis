# Service Performance Lessons

This note captures the main service-level performance issues we solved and the pattern to remember in future projects.

The goal is simple:
- avoid repeated work
- avoid query-per-row logic
- avoid loading data we do not need
- keep one shared query rule instead of duplicating it in many places

## 1. Do not repeat static work inside nested loops

### Real example
- `app/Services/V1/Occupancy/ContractAlertService.php`
- `app/Services/V1/Billing/PropertySubAlertService.php`

### The old risk
Alert jobs usually have this shape:
- contracts or subscriptions
- recipients per record
- channels per recipient such as SMS and email

That means one record can become many inner loop iterations.

If we build the same subject/message again and again inside the deepest loop, we waste CPU and make the code slower than it needs to be.

### What we improved
We changed the flow so each chunk does shared work once:
- load staff recipients once per chunk
- load existing alert logs once per chunk
- load enabled channels once per chunk

Then for each contract or subscription:
- build the message once
- reuse that same message for all recipients and channels

### Technical idea
Bad pattern:

```php
foreach ($contracts as $contract) {
    foreach ($recipients as $recipient) {
        foreach ($channels as $channel) {
            [$subject, $message] = $this->buildMessage($contract, $eventType);
        }
    }
}
```

Better pattern:

```php
foreach ($contracts as $contract) {
    [$subject, $message] = $this->buildMessage($contract, $eventType);

    foreach ($recipients as $recipient) {
        foreach ($channels as $channel) {
            // reuse subject and message
        }
    }
}
```

### Non-technical explanation
If you want to send one announcement to 20 people, you do not rewrite the same announcement 20 times. You write it once, then send it to all 20 people.

### Why it matters
- less repeated string formatting
- less repeated config work
- cleaner control flow
- easier to test and debug

### Files to remember
- `ContractAlertService::processContracts()`
- `ContractAlertService::buildMessage()`
- `PropertySubAlertService::processSubscriptions()`
- `PropertySubAlertService::buildMessage()`

## 2. Do not check active status one customer at a time

### Real example
- `app/Services/V1/Occupancy/CustomerContractRuleService.php`

### The old risk
Customer status depends on contracts.

If we loop through customers one by one and ask the database each time:
- "does this customer have an active contract?"

that becomes repeated existence queries.

This grows badly when there are many customers.

### What we improved
We changed to a set-based approach:
- get all active customer ids once
- activate matching customers in one update
- inactivate the remaining customers in one update

### Technical idea
Instead of:

```php
foreach ($customerIds as $customerId) {
    // run query to check active contract
    // update one customer
}
```

we now do:

```php
$activeCustomerIds = $this->activeCustomerContractQuery($today)
    ->whereIn('customer_id', $customerIds)
    ->distinct()
    ->pluck('customer_id');

Customer::query()->whereIn('id', $activeCustomerIds)->update([...]);
Customer::query()->whereNotIn('id', $activeCustomerIds)->update([...]);
```

### Non-technical explanation
Do not ask attendance one student at a time if the teacher already has the full attendance list. First get the full list, then mark everyone in one pass.

### Why it matters
- fewer queries
- better use of indexes
- easier to scale with many customers
- simpler update logic

### Supporting index lesson
When status depends on contract dates, the contract table needs supporting indexes around:
- `customer_id`
- `status`
- `start_date`
- `end_date`

Without that, even a good bulk query can still become slow.

### Files to remember
- `CustomerContractRuleService::syncCustomerStatuses()`
- `CustomerContractRuleService::syncAllCustomerStatuses()`
- `CustomerContractRuleService::activeCustomerContractQuery()`
- `CustomerContractRuleService::activeCustomerIdsSubquery()`

## 3. Load only the fields and relations you really need

### Real example
- `app/Services/V1/SubscriptionService.php`

### The old risk
Sometimes we fetch full models and full relations even though the screen only needs a few fields.

That causes:
- heavier queries
- bigger memory usage
- slower API responses

### What we improved
For property subscription status mapping we narrowed the query to only the fields required by the response:

```php
WorkspaceProperty::query()
    ->select(['id', 'tenant_id', 'property_uuid'])
    ->with([
        'subscription:id,workspace_property_id,status,current_period_starts_on,current_period_ends_on,expired_on',
    ])
```

### Non-technical explanation
If the receptionist only needs name and phone number, do not bring the whole filing cabinet.

### Why it matters
- smaller SQL payload
- lower memory usage
- less hydration overhead in Laravel
- safer for paginated list endpoints

### Files to remember
- `SubscriptionService::workspacePropertySubscriptionMap()`

## 4. Reuse one shared decoration or scope rule instead of repeating logic

### Real examples
- `app/Services/V1/SubscriptionService.php`
- `app/Services/V1/ContractReportService.php`
- `app/Services/V1/DashboardReportService.php`

### The old risk
When the same rule is repeated in many methods, two problems appear:
- performance tuning must be done many times
- one endpoint gets fixed while another still stays slow or inconsistent

### What we improved
We moved repeated logic into shared helpers such as:
- `decoratePropertyUsageRow()`
- `applyPropertyScopeToColumn()`

This means:
- one place computes property subscription status
- one place applies property scope filtering
- all endpoints stay consistent

### Technical example
Instead of rebuilding property scope conditions in every query, use one helper:

```php
$query = $this->applyPropertyScopeToColumn($query, $scope, 'property_floors.property_id');
```

### Non-technical explanation
If every branch office makes its own version of the same policy, mistakes happen. Better to keep one official policy and let all branches use it.

### Why it matters
- less duplicate code
- fewer hidden query differences
- easier profiling
- easier maintenance

### Files to remember
- `SubscriptionService::decoratePropertyUsageRow()`
- `ContractReportService::applyPropertyScopeToColumn()`
- `DashboardReportService::applyPropertyScopeToColumn()`

## 3 quick scenarios to remember for the next project

### Scenario 1: SMS or email alerts
Problem:
- 500 contracts
- each contract has 3 recipients
- 2 channels

If you rebuild the same message inside the deepest loop, you repeat work 3,000 times.

Better:
- build the message once per contract
- resolve channels once
- resolve recipients in bulk

### Scenario 2: Customer status sync
Problem:
- 2,000 customers
- each customer checked with a separate contract query

That can become 2,000 repeated checks.

Better:
- get active customer ids once
- run bulk update for active
- run bulk update for inactive

### Scenario 3: Property subscription list
Problem:
- property list page only needs subscription status
- query loads all columns and all related models

Better:
- select only needed columns
- eager load only the needed subscription fields
- compute shared output in one helper

## Simple review checklist before shipping a service

- Am I doing repeated work inside nested loops?
- Can I load this data once per chunk instead of once per row?
- Am I checking existence one record at a time instead of using one bulk query?
- Am I selecting only the fields needed by this endpoint?
- Am I repeating the same scope logic in many methods?
- Is there an index that supports this filter pattern?
- If this endpoint returns many rows, is pagination already in place?

## Final rule to remember

The main performance habit is:

**move work outward**

That means:
- from per-channel to per-recipient
- from per-recipient to per-record
- from per-record to per-chunk
- from per-row queries to set-based queries

That one habit alone prevents many future performance problems.
