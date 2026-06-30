<?php

namespace App\Services\V1\Billing;

use App\Models\Landlord\PropertySubscription;
use App\Models\Landlord\WorkspaceProperty;
use App\Models\Tenant\Property;
use App\Models\Tenancy\Tenant;
use App\Support\Tenancy\TenantConnectionManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WorkspacePropertyRegistryService
{
    private const WORKSPACE_PROPERTY_RELATIONS = [
        'subscription.billingRule:id,uuid,billing_profile_id,unit_price_cents,currency,status,effective_from,effective_to',
        'subscription.latestPayment',
        'latestPayment',
    ];

    /**
     * Create a new instance.
     */
    public function __construct(
        private TenantConnectionManager $tenantConnectionManager,
    ) {
    }

    /**
     * Handle ensure tenant synced.
     */
    public function ensureTenantSynced(Tenant $tenant): void
    {
        if (!WorkspaceProperty::query()->where('tenant_id', $tenant->id)->exists()) {
            $this->syncAllProperties($tenant);
        }
    }

    /**
     * Sync all properties.
     */
    public function syncAllProperties(Tenant $tenant): Collection
    {
        return $this->syncProperties($tenant);
    }

    /**
     * Sync property ids.
     */
    public function syncPropertyIds(Tenant $tenant, array $propertyIds): Collection
    {
        $propertyIds = array_values(array_unique(array_filter(array_map('intval', $propertyIds))));

        if ($propertyIds === []) {
            return collect();
        }

        return $this->syncProperties($tenant, $propertyIds);
    }

    /**
     * Sync property uuid.
     */
    public function syncPropertyUuid(Tenant $tenant, string $propertyUuid): ?WorkspaceProperty
    {
        $propertyUuid = trim($propertyUuid);

        if ($propertyUuid === '') {
            return null;
        }

        return $this->syncProperties($tenant, null, [$propertyUuid])->first();
    }

    /**
     * Resolve workspace property.
     */
    public function resolveWorkspaceProperty(Tenant $tenant, string $propertyUuid): ?WorkspaceProperty
    {
        $workspaceProperty = $this->workspacePropertyQuery($tenant)
            ->where('property_uuid', $propertyUuid)
            ->first();

        if ($workspaceProperty) {
            return $workspaceProperty;
        }

        return $this->syncPropertyUuid($tenant, $propertyUuid);
    }

    /**
     * Resolve workspace property for model.
     */
    public function resolveWorkspacePropertyForModel(Tenant $tenant, Property $property): ?WorkspaceProperty
    {
        $workspaceProperty = $this->workspacePropertyQuery($tenant)
            ->where('property_uuid', $property->uuid)
            ->first();

        if ($workspaceProperty) {
            return $workspaceProperty;
        }

        return $this->syncPropertyIds($tenant, [$property->id])->first();
    }

    /**
     * Handle mark property deleted.
     */
    public function markPropertyDeleted(Tenant $tenant, string $propertyUuid): void
    {
        WorkspaceProperty::query()
            ->where('tenant_id', $tenant->id)
            ->where('property_uuid', $propertyUuid)
            ->update([
                'property_deleted_at' => now(),
                'current_registered_units_total' => 0,
                'last_synced_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Sync ready tenants.
     */
    public function syncReadyTenants(?string $tenantUuid = null, int $chunk = 20, ?callable $progress = null): void
    {
        $chunk = max($chunk, 1);

        $query = Tenant::query()
            ->where('provisioning_status', 'ready')
            ->orderBy('id');

        if ($tenantUuid) {
            $tenant = (clone $query)->where('uuid', $tenantUuid)->firstOrFail();
            $this->syncTenantWithProgress($tenant, $progress);

            return;
        }

        $query->chunkById($chunk, function ($tenants) use ($progress) {
            foreach ($tenants as $tenant) {
                $this->syncTenantWithProgress($tenant, $progress);
            }
        });
    }

    /**
     * Sync tenant with progress.
     */
    private function syncTenantWithProgress(Tenant $tenant, ?callable $progress = null): void
    {
        try {
            $rows = $this->syncAllProperties($tenant);
            if ($progress !== null) {
                $progress('ok', $tenant, $rows->count(), null);
            }
        } catch (\Throwable $exception) {
            if ($progress !== null) {
                $progress('fail', $tenant, 0, $exception->getMessage());
            }
        }
    }

    /**
     * Sync properties.
     */
    private function syncProperties(Tenant $tenant, ?array $propertyIds = null, ?array $propertyUuids = null): Collection
    {
        $this->assertTenantReady($tenant);

        $rows = $this->runInTenantContext($tenant, function () use ($propertyIds, $propertyUuids) {
            $unitTotals = DB::connection($this->tenantConnectionManager->connectionName())
                ->table('property_floors')
                ->leftJoin('units', 'units.property_floor_id', '=', 'property_floors.id')
                ->select('property_floors.property_id')
                ->selectRaw('COUNT(units.id) as current_registered_units_total')
                ->groupBy('property_floors.property_id');

            $query = Property::query()
                ->leftJoinSub($unitTotals, 'unit_totals', 'unit_totals.property_id', '=', 'properties.id')
                ->select([
                    'properties.id',
                    'properties.uuid',
                    'properties.name',
                    'properties.status',
                    'properties.created_at',
                    'properties.updated_at',
                ])
                ->selectRaw('COALESCE(unit_totals.current_registered_units_total, 0) as current_registered_units_total');

            if ($propertyIds !== null && $propertyIds !== []) {
                $query->whereIn('properties.id', $propertyIds);
            }

            if ($propertyUuids !== null && $propertyUuids !== []) {
                $query->whereIn('properties.uuid', $propertyUuids);
            }

            return $query->get();
        });

        if ($rows->isEmpty()) {
            return collect();
        }

        $now = now();
        $payload = $rows->map(fn ($row) => [
            'uuid' => (string) str()->uuid(),
            'tenant_id' => $tenant->id,
            'property_uuid' => $row->uuid,
            'property_name' => $row->name,
            'property_status' => $row->status,
            'current_registered_units_total' => (int) $row->current_registered_units_total,
            'property_created_at' => $this->formatDateTime($row->created_at),
            'property_updated_at' => $this->formatDateTime($row->updated_at),
            'property_deleted_at' => null,
            'last_synced_at' => $now,
            'meta' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::connection('base')->transaction(function () use ($payload, $tenant, $now) {
            WorkspaceProperty::query()->upsert(
                $payload,
                ['tenant_id', 'property_uuid'],
                [
                    'property_name',
                    'property_status',
                    'current_registered_units_total',
                    'property_created_at',
                    'property_updated_at',
                    'property_deleted_at',
                    'last_synced_at',
                    'updated_at',
                ]
            );

            $workspaceProperties = WorkspaceProperty::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('property_uuid', array_column($payload, 'property_uuid'))
                ->get(['id', 'tenant_id', 'property_uuid']);

            $subscriptionPayload = $workspaceProperties->map(fn (WorkspaceProperty $workspaceProperty) => [
                'uuid' => (string) str()->uuid(),
                'tenant_id' => $workspaceProperty->tenant_id,
                'workspace_property_id' => $workspaceProperty->id,
                'status' => PropertySubscription::STATUS_UNSUBSCRIBED,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if ($subscriptionPayload !== []) {
                PropertySubscription::query()->insertOrIgnore($subscriptionPayload);
            }
        });

        return $this->workspacePropertyQuery($tenant)
            ->whereIn('property_uuid', array_column($payload, 'property_uuid'))
            ->orderBy('property_name')
            ->get();
    }

    /**
     * Shared landlord-side workspace property query used by resolve and sync flows.
     */
    private function workspacePropertyQuery(Tenant $tenant)
    {
        return WorkspaceProperty::query()
            ->with(self::WORKSPACE_PROPERTY_RELATIONS)
            ->where('tenant_id', $tenant->id);
    }

    /**
     * Run in tenant context.
     */
    private function runInTenantContext(Tenant $tenant, callable $callback): mixed
    {
        $currentTenant = Tenant::current();
        $this->tenantConnectionManager->activateTenant($tenant);

        try {
            return $callback();
        } finally {
            $this->tenantConnectionManager->restoreTenant($currentTenant);
        }
    }

    /**
     * Assert tenant ready.
     */
    private function assertTenantReady(Tenant $tenant): void
    {
        if ($tenant->provisioning_status !== 'ready' || empty($tenant->database)) {
            throw new InvalidArgumentException('Property subscriptions cannot be managed yet because workspace setup is not complete.');
        }
    }

    /**
     * Format date time.
     */
    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }
}
