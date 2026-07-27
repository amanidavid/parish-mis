<?php

namespace App\Services\V1\Billing;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PropertyInvoiceDeliveryLogService
{
    /**
     * List delivery logs for admin property invoice management.
     */
    public function listLogs(array $filters = []): LengthAwarePaginator
    {
        $query = DB::connection('base')->table('property_invoice_delivery_logs as delivery_logs')
            ->join('property_invoices', 'property_invoices.id', '=', 'delivery_logs.property_invoice_id')
            ->join('workspace_properties', 'workspace_properties.id', '=', 'property_invoices.workspace_property_id')
            ->join('tenants', 'tenants.id', '=', 'property_invoices.tenant_id')
            ->select([
                'delivery_logs.uuid',
                'delivery_logs.property_invoice_id',
                'delivery_logs.channel',
                'delivery_logs.kind',
                'delivery_logs.status',
                'delivery_logs.recipient_name',
                'delivery_logs.recipient_address',
                'delivery_logs.subject',
                'delivery_logs.message',
                'delivery_logs.attempts_count',
                'delivery_logs.last_attempt_at',
                'delivery_logs.sent_at',
                'delivery_logs.created_at',
                'delivery_logs.updated_at',
                'property_invoices.uuid as invoice_uuid',
                'property_invoices.invoice_number',
                'property_invoices.property_uuid',
                'property_invoices.issue_date',
                'property_invoices.due_date',
                'tenants.uuid as workspace_uuid',
                'tenants.display_name as workspace_display_name',
                'tenants.name as workspace_name_fallback',
                'workspace_properties.property_name',
            ])
            ->where(function ($builder) {
                $builder
                    ->whereNull('delivery_logs.kind')
                    ->orWhereIn('delivery_logs.kind', [
                        'paid_invoice_email',
                        'invoice_reminder_email',
                        'invoice_reminder_sms',
                    ]);
            });

        if (!empty($filters['status'] ?? null)) {
            $query->where('delivery_logs.status', $filters['status']);
        }

        if (!empty($filters['channel'] ?? null)) {
            $query->where('delivery_logs.channel', $filters['channel']);
        }

        if (!empty($filters['kind'] ?? null)) {
            $query->where('delivery_logs.kind', $filters['kind']);
        }

        if (!empty($filters['workspace_uuid'] ?? null)) {
            $query->where('tenants.uuid', $filters['workspace_uuid']);
        }

        if (!empty($filters['date_from'] ?? null)) {
            $query->where('delivery_logs.last_attempt_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (!empty($filters['date_to'] ?? null)) {
            $query->where('delivery_logs.last_attempt_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        if (!empty($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);

            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('property_invoices.invoice_number', 'like', $search.'%')
                    ->orWhere('delivery_logs.recipient_address', 'like', $search.'%')
                    ->orWhere('delivery_logs.recipient_name', 'like', $search.'%');
            });
        }

        $this->applySort($query, $filters['sort'] ?? null);

        $logs = $query->paginate((int) ($filters['per_page'] ?? 15))->withQueryString();

        $logs->getCollection()->transform(function ($row) {
            $row->workspace_name = $row->workspace_display_name ?: $row->workspace_name_fallback;

            unset($row->workspace_display_name, $row->workspace_name_fallback);

            return $row;
        });

        return $logs;
    }

    /**
     * Apply indexed sort.
     */
    private function applySort($query, ?string $sort): void
    {
        $sort = trim((string) $sort);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        match ($column) {
            'due_date' => $query->orderBy('property_invoices.due_date', $direction)->orderByDesc('delivery_logs.id'),
            'status' => $query->orderBy('delivery_logs.status', $direction)->orderByDesc('delivery_logs.last_attempt_at')->orderByDesc('delivery_logs.id'),
            'created_at' => $query->orderBy('delivery_logs.created_at', $direction)->orderByDesc('delivery_logs.id'),
            'last_attempt_at' => $query->orderBy('delivery_logs.last_attempt_at', $direction)->orderByDesc('delivery_logs.id'),
            default => $query->orderByDesc('delivery_logs.last_attempt_at')->orderByDesc('delivery_logs.id'),
        };
    }
}
