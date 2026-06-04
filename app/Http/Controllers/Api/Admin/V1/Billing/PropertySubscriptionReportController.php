<?php

namespace App\Http\Controllers\Api\Admin\V1\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\Billing\PropertySubscriptionExpiredIndexRequest;
use App\Http\Requests\Api\Admin\V1\Billing\PropertySubscriptionPaymentSummaryRequest;
use App\Http\Requests\Api\Admin\V1\Billing\PropertySubscriptionWorkspaceReportRequest;
use App\Services\V1\Billing\PropertySubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Arrayable;

class PropertySubscriptionReportController extends Controller
{
    public function __construct(
        private PropertySubscriptionService $propertySubscriptionService,
    ) {
    }

    public function paymentSummary(PropertySubscriptionPaymentSummaryRequest $request)
    {
        return ApiResponse::success(
            'Property subscription payment summary retrieved successfully.',
            $this->propertySubscriptionService->paymentCollectionSummary($request->validated())
        );
    }

    public function byWorkspace(PropertySubscriptionWorkspaceReportRequest $request)
    {
        $report = $this->propertySubscriptionService->workspaceReport($request->validated());

        return ApiResponse::success(
            'Workspace property subscription report retrieved successfully.',
            [
                'filters' => $report['filters'],
                'totals' => $report['totals'],
                ...$this->paginateRows($report['rows'], fn (array $row) => $this->formatWorkspaceReportRow($row)),
            ]
        );
    }

    public function expired(PropertySubscriptionExpiredIndexRequest $request)
    {
        $rows = $this->propertySubscriptionService->expiredPropertiesReport($request->validated());

        return ApiResponse::success(
            'Expired and unsubscribed property subscriptions retrieved successfully.',
            $this->paginateRows($rows, fn (array $row) => $this->formatExpiredPropertyRow($row))
        );
    }

    private function paginateRows(LengthAwarePaginator $rows, ?callable $transformer = null): array
    {
        $transformer ??= fn (array $row) => $row;

        return [
            'data' => collect($rows->items())
                ->map(fn ($row) => $transformer($this->normalizeRow($row)))
                ->values()
                ->all(),
            'links' => [
                'first' => $rows->url(1),
                'last' => $rows->url($rows->lastPage()),
                'prev' => $rows->previousPageUrl(),
                'next' => $rows->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $rows->currentPage(),
                'from' => $rows->firstItem(),
                'last_page' => $rows->lastPage(),
                'path' => $rows->path(),
                'per_page' => $rows->perPage(),
                'to' => $rows->lastItem(),
                'total' => $rows->total(),
            ],
        ];
    }

    private function formatWorkspaceReportRow(array $row): array
    {
        return [
            'workspace_uuid' => $row['workspace_uuid'] ?? $row['uuid'] ?? null,
            'workspace_name' => $row['workspace_name'] ?? $row['name'] ?? null,
            'workspace_display_name' => $row['workspace_display_name'] ?? $row['display_name'] ?? null,
            'workspace_status' => $row['workspace_status'] ?? $row['status'] ?? null,
            'provisioning_status' => $row['provisioning_status'] ?? null,
            'total_properties' => (int) ($row['total_properties'] ?? 0),
            'active_subscribed_properties' => (int) ($row['active_subscribed_properties'] ?? 0),
            'expired_properties' => (int) ($row['expired_properties'] ?? 0),
            'unsubscribed_properties' => (int) ($row['unsubscribed_properties'] ?? 0),
            'payments_count' => (int) ($row['payments_count'] ?? 0),
            'total_collected_amount_cents' => (int) ($row['total_collected_amount_cents'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function formatExpiredPropertyRow(array $row): array
    {
        return [
            'workspace_uuid' => $row['workspace_uuid'] ?? null,
            'workspace_name' => $row['workspace_name'] ?? null,
            'workspace_display_name' => $row['workspace_display_name'] ?? null,
            'property_uuid' => $row['property_uuid'] ?? null,
            'property_name' => $row['property_name'] ?? null,
            'property_status' => $row['property_status'] ?? null,
            'current_registered_units_total' => (int) ($row['current_registered_units_total'] ?? 0),
            'subscription_uuid' => $row['subscription_uuid'] ?? null,
            'stored_status' => $row['stored_status'] ?? null,
            'effective_status' => $row['effective_status'] ?? null,
            'current_period_starts_on' => $row['current_period_starts_on'] ?? null,
            'current_period_ends_on' => $row['current_period_ends_on'] ?? null,
            'last_paid_on' => $row['last_paid_on'] ?? null,
            'billing_rule_uuid' => $row['billing_rule_uuid'] ?? null,
            'range_start' => $row['range_start'] !== null ? (int) $row['range_start'] : null,
            'range_end' => $row['range_end'] !== null ? (int) $row['range_end'] : null,
            'price_cents' => $row['price_cents'] !== null ? (int) $row['price_cents'] : null,
            'currency' => $row['currency'] ?? null,
        ];
    }

    private function normalizeRow(mixed $row): array
    {
        if ($row instanceof Arrayable) {
            return $row->toArray();
        }

        if (is_array($row)) {
            return $row;
        }

        return (array) $row;
    }
}
