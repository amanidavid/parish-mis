<?php

namespace App\Services\V1\Billing;

use App\Models\Landlord\BillingRule;
use App\Models\Landlord\PropertyInvoice;
use App\Models\Landlord\PropertyInvoiceDeliveryLog;
use App\Models\Landlord\PropertyInvoiceItem;
use App\Models\Landlord\PropertySubscription;
use App\Models\Landlord\PropertySubscriptionPayment;
use App\Models\Landlord\WorkspaceProperty;
use App\Models\Tenancy\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PropertyInvoiceService
{
    public function __construct(
        private WorkspaceBillingRuleService $workspaceBillingRuleService,
    ) {
    }

    public function listPropertyInvoices(Tenant $tenant, string $propertyUuid, array $filters = []): LengthAwarePaginator
    {
        $workspaceProperty = $this->resolveWorkspaceProperty($tenant, $propertyUuid);
        $this->syncOverdueInvoices($tenant->id, $workspaceProperty->id);

        $query = PropertyInvoice::query()
            ->with(['workspaceProperty', 'items'])
            ->where('tenant_id', $tenant->id)
            ->where('workspace_property_id', $workspaceProperty->id);

        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['start_date'] ?? null)) {
            $query->where('issue_date', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'] ?? null)) {
            $query->where('issue_date', '<=', $filters['end_date']);
        }

        $this->applySort($query, $filters['sort'] ?? null);

        return $query->paginate((int) ($filters['per_page'] ?? 15))->withQueryString();
    }

    public function getPropertyInvoice(Tenant $tenant, string $propertyUuid, string $invoiceUuid): ?PropertyInvoice
    {
        $workspaceProperty = $this->resolveWorkspaceProperty($tenant, $propertyUuid);
        $this->syncOverdueInvoices($tenant->id, $workspaceProperty->id);

        $invoice = PropertyInvoice::query()
            ->with(['workspaceProperty', 'items', 'deliveryLogs'])
            ->where('tenant_id', $tenant->id)
            ->where('workspace_property_id', $workspaceProperty->id)
            ->where('uuid', $invoiceUuid)
            ->first();

        if (!$invoice) {
            return null;
        }

        $this->refreshStatus($invoice);

        return $invoice->fresh(['workspaceProperty', 'items', 'deliveryLogs']);
    }

    public function ensureInvoiceForUpcomingPeriod(
        Tenant $tenant,
        int $workspacePropertyId,
        ?int $propertySubscriptionId,
        CarbonInterface|string $dueDate,
        array $meta = []
    ): PropertyInvoice
    {
        $dueDate = Carbon::parse($dueDate)->startOfDay();

        return DB::connection('base')->transaction(function () use ($tenant, $workspacePropertyId, $propertySubscriptionId, $dueDate, $meta) {
            $workspaceProperty = WorkspaceProperty::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($workspacePropertyId)
                ->lockForUpdate()
                ->firstOrFail();

            $periodStartsOn = $dueDate->copy()->addDay();
            $periodEndsOn = $periodStartsOn->copy()->addMonth()->subDay();
            $billingRule = $this->workspaceBillingRuleService->requireActiveRule($dueDate);
            $quantity = max((int) $workspaceProperty->current_registered_units_total, 0);
            $unitPriceCents = (int) $billingRule->unit_price_cents;
            $totalAmountCents = $this->workspaceBillingRuleService->calculateMonthlyCharge($quantity, $billingRule);

            $invoice = PropertyInvoice::query()
                ->where('workspace_property_id', $workspaceProperty->id)
                ->whereDate('period_starts_on', $periodStartsOn->toDateString())
                ->whereDate('period_ends_on', $periodEndsOn->toDateString())
                ->lockForUpdate()
                ->first();

            if ($invoice) {
                return $invoice;
            }

            $sequenceNumber = $this->nextSequenceNumber((int) $workspaceProperty->id, (int) $periodStartsOn->year);
            $invoice = PropertyInvoice::query()->create([
                'tenant_id' => $tenant->id,
                'workspace_property_id' => $workspaceProperty->id,
                'property_subscription_id' => $propertySubscriptionId,
                'property_uuid' => $workspaceProperty->property_uuid,
                'invoice_number' => sprintf('INV-%d-%d-%d', $periodStartsOn->year, $this->invoicePropertyReference($workspaceProperty), $sequenceNumber),
                'invoice_year' => (int) $periodStartsOn->year,
                'sequence_number' => $sequenceNumber,
                'status' => PropertyInvoice::STATUS_UNPAID,
                'currency' => $billingRule->currency ?? 'TZS',
                'issue_date' => now()->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'period_starts_on' => $periodStartsOn->toDateString(),
                'period_ends_on' => $periodEndsOn->toDateString(),
                'quantity' => $quantity,
                'unit_price_cents' => $unitPriceCents,
                'subtotal_amount_cents' => $totalAmountCents,
                'total_amount_cents' => $totalAmountCents,
                'balance_amount_cents' => $totalAmountCents,
                'meta' => [
                    'property_name' => $workspaceProperty->property_name,
                    'current_registered_units_total' => $quantity,
                    'billing_rule_uuid' => $billingRule->uuid,
                    'tenant_full_name' => $meta['tenant_full_name'] ?? ($tenant->display_name ?: $tenant->name),
                    'tenant_email' => $meta['tenant_email'] ?? null,
                    'tenant_phone' => $meta['tenant_phone'] ?? null,
                ],
            ]);

            PropertyInvoiceItem::query()->create([
                'property_invoice_id' => $invoice->id,
                'description' => $workspaceProperty->property_name,
                'payment_period_label' => $periodStartsOn->toDateString().' - '.$periodEndsOn->toDateString(),
                'quantity' => $quantity,
                'unit_price_cents' => $unitPriceCents,
                'amount_cents' => $totalAmountCents,
                'meta' => [
                    'property_uuid' => $workspaceProperty->property_uuid,
                    'billing_rule_uuid' => $billingRule->uuid,
                ],
            ]);

            return $invoice;
        });
    }

    public function markInvoicePaidForPayment(PropertySubscriptionPayment $payment): void
    {
        $invoice = PropertyInvoice::query()
            ->where('workspace_property_id', $payment->workspace_property_id)
            ->whereDate('period_starts_on', $payment->coverage_starts_on?->toDateString())
            ->whereDate('period_ends_on', $payment->coverage_ends_on?->toDateString())
            ->first();

        if (!$invoice) {
            return;
        }

        $invoice->forceFill([
            'status' => PropertyInvoice::STATUS_PAID,
            'balance_amount_cents' => 0,
            'paid_at' => $payment->payment_date ?: now(),
        ])->save();
    }

    public function ensurePaidInvoiceForPayment(
        Tenant $tenant,
        WorkspaceProperty $workspaceProperty,
        ?PropertySubscription $subscription,
        BillingRule $billingRule,
        PropertySubscriptionPayment $payment
    ): PropertyInvoice {
        $recipient = $this->resolveTenantBillingRecipient($tenant);
        $periodStartsOn = Carbon::parse($payment->coverage_starts_on)->startOfDay();
        $periodEndsOn = Carbon::parse($payment->coverage_ends_on)->startOfDay();
        $issueDate = Carbon::parse($payment->payment_date)->startOfDay();

        $invoice = PropertyInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('workspace_property_id', $workspaceProperty->id)
            ->whereDate('period_starts_on', $periodStartsOn->toDateString())
            ->whereDate('period_ends_on', $periodEndsOn->toDateString())
            ->lockForUpdate()
            ->first();

        if (!$invoice) {
            $sequenceNumber = $this->nextSequenceNumber((int) $workspaceProperty->id, (int) $periodStartsOn->year);

            $invoice = PropertyInvoice::query()->create([
                'tenant_id' => $tenant->id,
                'workspace_property_id' => $workspaceProperty->id,
                'property_subscription_id' => $subscription?->id,
                'property_uuid' => $workspaceProperty->property_uuid,
                'invoice_number' => sprintf('INV-%d-%d-%d', $periodStartsOn->year, $this->invoicePropertyReference($workspaceProperty), $sequenceNumber),
                'invoice_year' => (int) $periodStartsOn->year,
                'sequence_number' => $sequenceNumber,
                'status' => PropertyInvoice::STATUS_PAID,
                'currency' => $payment->currency ?: ($billingRule->currency ?? 'TZS'),
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $issueDate->toDateString(),
                'period_starts_on' => $periodStartsOn->toDateString(),
                'period_ends_on' => $periodEndsOn->toDateString(),
                'quantity' => (int) $payment->unit_count_at_payment,
                'unit_price_cents' => (int) $payment->unit_price_cents_at_payment,
                'subtotal_amount_cents' => (int) $payment->total_amount_cents,
                'total_amount_cents' => (int) $payment->total_amount_cents,
                'balance_amount_cents' => 0,
                'paid_at' => $issueDate,
                'meta' => [
                    'property_name' => $workspaceProperty->property_name,
                    'current_registered_units_total' => (int) $payment->unit_count_at_payment,
                    'billing_rule_uuid' => $billingRule->uuid,
                    'tenant_full_name' => $recipient['name'] ?: ($tenant->display_name ?: $tenant->name),
                    'tenant_email' => $recipient['email'],
                    'tenant_phone' => $recipient['phone'],
                    'payment_uuid' => $payment->uuid,
                ],
            ]);

            PropertyInvoiceItem::query()->create([
                'property_invoice_id' => $invoice->id,
                'description' => $workspaceProperty->property_name,
                'payment_period_label' => $periodStartsOn->toDateString().' - '.$periodEndsOn->toDateString(),
                'quantity' => (int) $payment->unit_count_at_payment,
                'unit_price_cents' => (int) $payment->unit_price_cents_at_payment,
                'amount_cents' => (int) $payment->total_amount_cents,
                'meta' => [
                    'property_uuid' => $workspaceProperty->property_uuid,
                    'billing_rule_uuid' => $billingRule->uuid,
                    'payment_uuid' => $payment->uuid,
                ],
            ]);

            return $invoice->loadMissing(['workspaceProperty', 'items']);
        }

        $invoice->forceFill([
            'property_subscription_id' => $subscription?->id,
            'status' => PropertyInvoice::STATUS_PAID,
            'currency' => $payment->currency ?: ($billingRule->currency ?? $invoice->currency),
            'issue_date' => $invoice->issue_date ?: $issueDate->toDateString(),
            'due_date' => $invoice->due_date ?: $issueDate->toDateString(),
            'quantity' => (int) $payment->unit_count_at_payment,
            'unit_price_cents' => (int) $payment->unit_price_cents_at_payment,
            'subtotal_amount_cents' => (int) $payment->total_amount_cents,
            'total_amount_cents' => (int) $payment->total_amount_cents,
            'balance_amount_cents' => 0,
            'paid_at' => $issueDate,
            'meta' => array_merge($invoice->meta ?? [], [
                'tenant_full_name' => $recipient['name'] ?: data_get($invoice->meta, 'tenant_full_name', $tenant->display_name ?: $tenant->name),
                'tenant_email' => $recipient['email'] ?: data_get($invoice->meta, 'tenant_email'),
                'tenant_phone' => $recipient['phone'] ?: data_get($invoice->meta, 'tenant_phone'),
                'payment_uuid' => $payment->uuid,
            ]),
        ])->save();

        $invoice->items()->updateOrCreate(
            ['property_invoice_id' => $invoice->id],
            [
                'description' => $workspaceProperty->property_name,
                'payment_period_label' => $periodStartsOn->toDateString().' - '.$periodEndsOn->toDateString(),
                'quantity' => (int) $payment->unit_count_at_payment,
                'unit_price_cents' => (int) $payment->unit_price_cents_at_payment,
                'amount_cents' => (int) $payment->total_amount_cents,
                'meta' => array_merge($invoice->items()->first()?->meta ?? [], [
                    'property_uuid' => $workspaceProperty->property_uuid,
                    'billing_rule_uuid' => $billingRule->uuid,
                    'payment_uuid' => $payment->uuid,
                ]),
            ]
        );

        return $invoice->fresh(['workspaceProperty', 'items']);
    }

    public function markInvoiceDelivery(
        PropertyInvoice $invoice,
        string $channel,
        string $status,
        string $recipientAddress,
        ?string $recipientName = null,
        ?string $subject = null,
        ?string $message = null,
        array $meta = []
    ): void
    {
        PropertyInvoiceDeliveryLog::query()->create([
            'property_invoice_id' => $invoice->id,
            'channel' => $channel,
            'status' => $status,
            'recipient_name' => $recipientName,
            'recipient_address' => $recipientAddress,
            'subject' => $subject,
            'message' => $message,
            'attempts_count' => 1,
            'last_attempt_at' => now(),
            'sent_at' => $status === 'sent' ? now() : null,
            'meta' => $meta !== [] ? $meta : null,
        ]);

        if ($status === 'sent') {
            $invoice->forceFill([
                'sent_at' => now(),
            ])->save();
        }
    }

    public function hasSuccessfulDelivery(PropertyInvoice $invoice, string $channel, string $recipientAddress, string $kind): bool
    {
        return PropertyInvoiceDeliveryLog::query()
            ->where('property_invoice_id', $invoice->id)
            ->where('channel', $channel)
            ->where('status', 'sent')
            ->whereRaw('LOWER(recipient_address) = ?', [mb_strtolower(trim($recipientAddress), 'UTF-8')])
            ->where('meta->kind', $kind)
            ->exists();
    }

    private function resolveWorkspaceProperty(Tenant $tenant, string $propertyUuid): WorkspaceProperty
    {
        return WorkspaceProperty::query()
            ->where('tenant_id', $tenant->id)
            ->where('property_uuid', $propertyUuid)
            ->whereNull('property_deleted_at')
            ->firstOrFail();
    }

    private function nextSequenceNumber(int $workspacePropertyId, int $invoiceYear): int
    {
        return (int) PropertyInvoice::query()
            ->where('workspace_property_id', $workspacePropertyId)
            ->where('invoice_year', $invoiceYear)
            ->max('sequence_number') + 1;
    }

    private function invoicePropertyReference(WorkspaceProperty $workspaceProperty): int
    {
        return (int) ($workspaceProperty->tenant_property_sequence ?: $workspaceProperty->id);
    }

    private function resolveTenantBillingRecipient(Tenant $tenant): array
    {
        $recipient = DB::connection('base')->table('user_tenants')
            ->join('users', 'users.id', '=', 'user_tenants.user_id')
            ->where('user_tenants.tenant_id', $tenant->id)
            ->where('user_tenants.is_owner', true)
            ->select([
                'users.name',
                'users.email',
                'users.phone',
            ])
            ->first();

        return [
            'name' => $recipient->name ?? null,
            'email' => $recipient->email ?? null,
            'phone' => $recipient->phone ?? null,
        ];
    }

    private function refreshStatus(PropertyInvoice $invoice): void
    {
        if ($invoice->status === PropertyInvoice::STATUS_PAID) {
            return;
        }

        $nextStatus = $invoice->due_date && $invoice->due_date->lt(Carbon::today())
            ? PropertyInvoice::STATUS_OVERDUE
            : PropertyInvoice::STATUS_UNPAID;

        if ($invoice->status !== $nextStatus) {
            $invoice->forceFill(['status' => $nextStatus])->save();
        }
    }

    private function syncOverdueInvoices(int $tenantId, int $workspacePropertyId): void
    {
        PropertyInvoice::query()
            ->where('tenant_id', $tenantId)
            ->where('workspace_property_id', $workspacePropertyId)
            ->where('status', '!=', PropertyInvoice::STATUS_PAID)
            ->whereDate('due_date', '<', Carbon::today()->toDateString())
            ->update(['status' => PropertyInvoice::STATUS_OVERDUE]);
    }

    private function applySort($query, ?string $sort): void
    {
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');

        match ($column) {
            'issue_date' => $query->orderBy('issue_date', $direction)->orderBy('id', 'desc'),
            'due_date' => $query->orderBy('due_date', $direction)->orderBy('id', 'desc'),
            'status' => $query->orderBy('status', $direction)->orderBy('due_date')->orderBy('id', 'desc'),
            'total_amount_cents' => $query->orderBy('total_amount_cents', $direction)->orderBy('id', 'desc'),
            'created_at' => $query->orderBy('created_at', $direction)->orderBy('id', 'desc'),
            default => $query->orderBy('due_date')->orderBy('id', 'desc'),
        };
    }
}
