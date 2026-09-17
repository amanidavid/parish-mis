<?php

namespace App\Services\V1\Billing;

use App\Models\Landlord\BaseUser;
use App\Models\Landlord\HistoricalContractEntryGrant;
use App\Models\Tenant\Property;
use App\Models\Tenancy\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class HistoricalContractEntryGrantService
{
    public function __construct(
        private WorkspacePropertyRegistryService $workspacePropertyRegistryService,
    ) {
    }

    public function paginateForTenant(Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $query = HistoricalContractEntryGrant::query()
            ->with(['workspaceProperty', 'approvedBy', 'revokedBy'])
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('approved_at')
            ->orderByDesc('id');

        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (($filters['scope'] ?? null) === 'workspace') {
            $query->whereNull('workspace_property_id');
        }

        if (($filters['scope'] ?? null) === 'property') {
            $query->whereNotNull('workspace_property_id');
        }

        return $query->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function create(Tenant $tenant, array $data, ?BaseUser $approvedBy): HistoricalContractEntryGrant
    {
        $workspacePropertyId = null;

        if (!empty($data['property_uuid'] ?? null)) {
            $workspaceProperty = $this->workspacePropertyRegistryService->resolveWorkspaceProperty($tenant, $data['property_uuid']);

            if (!$workspaceProperty || $workspaceProperty->property_deleted_at !== null) {
                throw new InvalidArgumentException('The selected property is not available in this workspace.');
            }

            $workspacePropertyId = $workspaceProperty->id;
        }

        return HistoricalContractEntryGrant::query()->create([
            'tenant_id' => $tenant->id,
            'workspace_property_id' => $workspacePropertyId,
            'status' => HistoricalContractEntryGrant::STATUS_ACTIVE,
            'historical_start_date_from' => $data['historical_start_date_from'],
            'historical_start_date_to' => $data['historical_start_date_to'],
            'usable_from' => $data['usable_from'] ?? now(),
            'expires_at' => $data['expires_at'],
            'reason' => trim($data['reason']),
            'approved_by_user_id' => $approvedBy?->id,
            'approved_at' => now(),
            'meta' => $data['meta'] ?? null,
        ])->load(['workspaceProperty', 'approvedBy', 'revokedBy']);
    }

    public function revoke(Tenant $tenant, string $grantUuid, string $reason, ?BaseUser $revokedBy): HistoricalContractEntryGrant
    {
        $grant = $this->findForTenant($tenant, $grantUuid);

        if ($grant->status !== HistoricalContractEntryGrant::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Only an active historical contract entry grant can be revoked.');
        }

        $grant->update([
            'status' => HistoricalContractEntryGrant::STATUS_REVOKED,
            'revocation_reason' => trim($reason),
            'revoked_by_user_id' => $revokedBy?->id,
            'revoked_at' => now(),
        ]);

        return $grant->fresh(['workspaceProperty', 'approvedBy', 'revokedBy']);
    }

    public function allowsHistoricalStartDate(Tenant $tenant, Property $property, string $contractStartDate): bool
    {
        $workspaceProperty = $this->workspacePropertyRegistryService->resolveWorkspacePropertyForModel($tenant, $property);

        if (!$workspaceProperty || $workspaceProperty->property_deleted_at !== null) {
            return false;
        }

        $now = now();
        $date = Carbon::parse($contractStartDate)->toDateString();

        return HistoricalContractEntryGrant::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', HistoricalContractEntryGrant::STATUS_ACTIVE)
            ->where('usable_from', '<=', $now)
            ->where('expires_at', '>', $now)
            ->where('historical_start_date_from', '<=', $date)
            ->where('historical_start_date_to', '>=', $date)
            ->where(function ($query) use ($workspaceProperty) {
                $query->whereNull('workspace_property_id')
                    ->orWhere('workspace_property_id', $workspaceProperty->id);
            })
            ->exists();
    }

    private function findForTenant(Tenant $tenant, string $grantUuid): HistoricalContractEntryGrant
    {
        return HistoricalContractEntryGrant::query()
            ->where('tenant_id', $tenant->id)
            ->where('uuid', $grantUuid)
            ->firstOrFail();
    }
}
