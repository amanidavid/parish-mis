# Currency Implementation Test Plan

## Purpose

This test plan verifies the new property currency flow:

- country provides the default currency suggestion
- property stores the authoritative currency
- units inherit property currency
- contracts use property currency
- property currency cannot change after contract history exists

Use this plan in order so each later test has the right setup data.

## Prerequisites

- app tenant user is authenticated
- tenant workspace is active and allows property/unit/contract operations
- tenant has seeded location data
- at least one `property type`, one `region`, one `district`, one `ward`, and one `customer` available

Recommended reference countries:

- Tanzania -> `TZS`
- United States -> `USD`

## Base URL

All endpoints below use:

```text
/api/v1/app
```

## Test 1: Confirm country returns currency code

### Request

`GET /api/v1/app/locations/countries?search=tan&per_page=15`

### Expectation

- response status is `200`
- `success` is `true`
- at least one country item contains:
  - `uuid`
  - `name`
  - `code`
  - `currency_code`
- Tanzania should return `currency_code = "TZS"`

### Expected item shape

```json
{
  "uuid": "country-uuid",
  "name": "Tanzania",
  "code": "TZ",
  "currency_code": "TZS",
  "dial_code": "255",
  "status": "active"
}
```

## Test 2: Create property without sending currency

Purpose: confirm backend derives property currency from selected country.

### Request

`POST /api/v1/app/properties`

```json
{
  "name": "Currency Test Property TZS",
  "type_uuid": "property-type-uuid",
  "country_uuid": "tanzania-country-uuid",
  "region_uuid": "region-uuid",
  "district_uuid": "district-uuid",
  "ward_uuid": "ward-uuid",
  "address_line": "Test area",
  "postal_code": "14112",
  "status": "active"
}
```

### Expectation

- response status is `201`
- `data.currency` equals `TZS`
- `data.location.country.currency_code` equals `TZS`

### Save for later

- `property_uuid_tzs`

## Test 3: Create property with manual currency override

Purpose: confirm frontend can auto-fill from country but still allow override before usage.

### Request

`POST /api/v1/app/properties`

```json
{
  "name": "Currency Test Property USD Override",
  "type_uuid": "property-type-uuid",
  "country_uuid": "tanzania-country-uuid",
  "region_uuid": "region-uuid",
  "district_uuid": "district-uuid",
  "ward_uuid": "ward-uuid",
  "currency": "USD",
  "status": "active"
}
```

### Expectation

- response status is `201`
- `data.currency` equals `USD`
- `data.location.country.currency_code` still equals `TZS`

### Save for later

- `property_uuid_usd_override`

## Test 4: Update property currency before any contract exists

Purpose: confirm currency is editable while property has no contract history.

### Request

`PATCH /api/v1/app/properties/{property_uuid_usd_override}`

```json
{
  "currency": "EUR"
}
```

### Expectation

- response status is `200`
- `data.currency` equals `EUR`

## Test 5: Create unit and confirm it inherits property currency

Purpose: property should be the source of truth for unit currency.

### Request

`POST /api/v1/app/units`

```json
{
  "property_floor_uuid": "floor-under-property_uuid_tzs",
  "unit_number": "CUR-A-01",
  "monthly_rent_amount": 450000,
  "status": "vacant"
}
```

### Expectation

- response status is `201`
- `data.monthly_rent_amount` equals `450000`
- `data.rent_currency` equals property currency, expected `TZS`

### Save for later

- `unit_uuid_tzs`

## Test 6: Try creating unit with a mismatched currency

Purpose: frontend should not be able to save a different unit currency from the property.

### Request

`POST /api/v1/app/units`

```json
{
  "property_floor_uuid": "floor-under-property_uuid_tzs",
  "unit_number": "CUR-A-02",
  "monthly_rent_amount": 500000,
  "rent_currency": "USD",
  "status": "vacant"
}
```

### Expectation

- response status is `422`
- `success` is `false`
- `message` equals `Unit currency must match property currency.`
- `errors.rent_currency` is present

### Expected error

```json
{
  "success": false,
  "message": "Unit currency must match property currency.",
  "data": null,
  "errors": {
    "rent_currency": [
      "Unit currency must match the property currency."
    ]
  }
}
```

## Test 7: Get next contract number and confirm currency preview

Purpose: contract screen should read preview currency from backend.

### Request

`GET /api/v1/app/customer-contracts/next-number?unit_uuid={unit_uuid_tzs}&start_date=2026-07-10`

### Expectation

- response status is `200`
- `data.unit_uuid` matches the unit
- `data.rent_currency` equals `TZS`
- `data.monthly_rent_amount` equals the unit amount

## Test 8: Create contract and confirm contract currency

Purpose: backend should derive contract currency automatically.

### Request

`POST /api/v1/app/customer-contracts`

```json
{
  "customer_uuid": "customer-from-same-property-uuid",
  "unit_uuid": "unit_uuid_tzs",
  "start_date": "2026-07-10",
  "contract_months": 12,
  "status": "active",
  "notes": "Currency test contract"
}
```

### Expectation

- response status is `201`
- `data.currency` equals `TZS`
- `data.unit.rent_currency` equals `TZS`
- `data.unit_price_at_contract` equals unit rent amount

### Save for later

- `contract_uuid_tzs`

## Test 9: Try changing property currency after contract history exists

Purpose: protect historical contract financial consistency.

### Request

`PATCH /api/v1/app/properties/{property_uuid_tzs}`

```json
{
  "currency": "USD"
}
```

### Expectation

- response status is `422`
- `success` is `false`
- `message` equals `Property currency cannot be changed.`
- `errors.currency` is present

### Expected error

```json
{
  "success": false,
  "message": "Property currency cannot be changed.",
  "data": null,
  "errors": {
    "currency": [
      "Property currency cannot be changed after contract history already exists for this property."
    ]
  }
}
```

## Test 10: Confirm property details still show stable currency

### Request

`GET /api/v1/app/properties/{property_uuid_tzs}`

### Expectation

- response status is `200`
- `data.currency` still equals `TZS`
- `data.location.country.currency_code` equals `TZS`

## Test 11: Confirm unit details still show stable currency

### Request

`GET /api/v1/app/units/{unit_uuid_tzs}`

### Expectation

- response status is `200`
- `data.rent_currency` equals `TZS`

## Test 12: Confirm contract details still show stable currency

### Request

`GET /api/v1/app/customer-contracts/{contract_uuid_tzs}`

### Expectation

- response status is `200`
- `data.currency` equals `TZS`
- `data.unit.rent_currency` equals `TZS`

## Test 13: Property list should expose property currency

### Request

`GET /api/v1/app/properties?per_page=15`

### Expectation

- response status is `200`
- every property item includes `currency`
- every property item country block includes `currency_code` when country is loaded

## Test 14: Country change without explicit currency should not silently rewrite existing property currency

Purpose: backend currently preserves property currency on update unless frontend explicitly sends a new currency.

### Setup

Use a property with no contract history and current currency `EUR`.

### Request

`PATCH /api/v1/app/properties/{property_uuid_usd_override}`

```json
{
  "country_uuid": "united-states-country-uuid"
}
```

### Expectation

- response status is `200`
- `data.location.country.code` changes to `US`
- `data.location.country.currency_code` changes to `USD`
- `data.currency` remains `EUR`

This is important for frontend: changing country alone does not force a new property currency once the property already exists.

## Optional Existing Data Check

Purpose: inspect old records after migration/backfill.

### Check A: Old properties

- open existing old properties
- confirm `currency` is now populated

### Check B: Old units

- open existing old units
- confirm `rent_currency` is populated and matches property currency

### Check C: Old contracts

- old contracts with `TZS` may remain `TZS`
- this is expected unless a separate safe cleanup is implemented later

## Frontend Usage Rules

- auto-fill property currency from `country.currency_code` during create
- still allow manual property currency change before contract history exists
- after contract history exists, make property currency read-only in UI
- do not let frontend compute a separate unit currency
- do not send contract currency during contract create
- always use `contract.currency` for contract totals and receipts
- never use current property currency to rewrite historical contract totals on the client

## Quick Pass Criteria

Implementation is considered correct if all these pass:

- country endpoint returns `currency_code`
- property create defaults currency from country
- property create can manually override currency
- unit create inherits property currency
- mismatched unit currency is rejected
- contract preview returns correct currency
- contract create stores correct currency
- property currency change is blocked after contract history exists
- property, unit, and contract read endpoints all return consistent currency values
