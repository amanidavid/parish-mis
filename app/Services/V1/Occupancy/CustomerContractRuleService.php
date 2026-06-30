<?php

namespace App\Services\V1\Occupancy;

use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerContract;
use App\Models\Tenant\Unit;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class CustomerContractRuleService
{
    public const OPEN_ENDED_CONTRACT_END_DATE = '9999-12-31';
    public const ACTIVE_OCCUPANCY_CONTRACT_STATUSES = ['active'];
    public const OVERLAP_BLOCKING_CONTRACT_STATUSES = ['draft', 'active', 'terminated'];

    /**
     * Handle find duplicate customer.
     */
    public function findDuplicateCustomer(array $payload, int $propertyId, ?int $ignoreCustomerId = null): ?Customer
    {
        $query = Customer::query()
            ->where('property_id', $propertyId)
            ->where('customer_type', $payload['customer_type']);

        if ($ignoreCustomerId) {
            $query->whereKeyNot($ignoreCustomerId);
        }

        if ($payload['customer_type'] === 'business') {
            $businessDetails = $payload['business_details'] ?? [];
            $businessName = $this->normalize($businessDetails['business_name'] ?? $payload['display_name'] ?? null);
            $registrationNumber = $this->normalize($businessDetails['registration_number'] ?? null);
            $taxIdentifier = $this->normalize($businessDetails['tax_identifier'] ?? null);

            return $query
                ->where(function (Builder $duplicateQuery) use ($businessName, $registrationNumber, $taxIdentifier) {
                    if ($registrationNumber) {
                        $duplicateQuery->orWhereHas('businessDetail', fn (Builder $businessQuery) => $businessQuery->where('registration_number', $registrationNumber));
                    }

                    if ($taxIdentifier) {
                        $duplicateQuery->orWhereHas('businessDetail', fn (Builder $businessQuery) => $businessQuery->where('tax_identifier', $taxIdentifier));
                    }

                    if ($businessName) {
                        $duplicateQuery->orWhereHas('businessDetail', fn (Builder $businessQuery) => $businessQuery->where('business_name', $businessName));
                    }
                })
                ->first();
        }

        $displayName = $this->normalize($payload['display_name'] ?? null);
        $email = $this->normalize($payload['email'] ?? null);
        $phone = $this->normalizePhone($payload['phone'] ?? null);

        return $query
            ->where(function (Builder $duplicateQuery) use ($displayName, $email, $phone) {
                if ($email) {
                    $duplicateQuery->orWhere('email', $email);
                }

                if ($phone) {
                    $duplicateQuery->orWhere('phone', $phone);
                }

                if ($displayName && !$email && !$phone) {
                    $duplicateQuery->orWhere('display_name', $displayName);
                }
            })
            ->first();
    }

    /**
     * Determine whether has overlapping unit contract.
     */
    public function hasOverlappingUnitContract(int $unitId, string $startDate, ?string $endDate, ?int $ignoreContractId = null): bool
    {
        $query = CustomerContract::query()
            ->where('unit_id', $unitId)
            ->where(function (Builder $statusQuery) use ($startDate, $endDate) {
                $statusQuery
                    ->where(function (Builder $activeQuery) use ($startDate, $endDate) {
                        $activeQuery
                            ->whereIn('status', ['draft', 'active'])
                            ->where('start_date', '<=', $endDate ?? self::OPEN_ENDED_CONTRACT_END_DATE)
                            ->where(function (Builder $innerQuery) use ($startDate) {
                                $innerQuery
                                    ->whereNull('end_date')
                                    ->orWhere('end_date', '>=', $startDate);
                            });
                    })
                    ->orWhere(function (Builder $terminatedQuery) use ($startDate, $endDate) {
                        $terminatedQuery
                            ->where('status', 'terminated')
                            ->whereNotNull('termination_date')
                            ->where('start_date', '<=', $endDate ?? self::OPEN_ENDED_CONTRACT_END_DATE)
                            ->where('termination_date', '>', $startDate);
                    });
            });

        if ($ignoreContractId) {
            $query->whereKeyNot($ignoreContractId);
        }

        return $query->exists();
    }

    /**
     * Sync unit occupancy status.
     */
    public function syncUnitOccupancyStatus(int $unitId): void
    {
        $today = Carbon::today()->toDateString();

        $hasActiveOccupancy = $this->activeCustomerContractQuery($today)
            ->where('unit_id', $unitId)
            ->exists();

        Unit::query()
            ->whereKey($unitId)
            ->update([
                'status' => $hasActiveOccupancy ? 'occupied' : 'vacant',
            ]);
    }

    /**
     * Sync one or more unit occupancy statuses from their effective active contracts.
     *
     * @param  array<int, int>  $unitIds
     */
    public function syncUnitOccupancyStatuses(array $unitIds): int
    {
        $unitIds = collect($unitIds)
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($unitIds === []) {
            return 0;
        }

        $today = Carbon::today()->toDateString();
        $activeUnitIds = $this->activeCustomerContractQuery($today)
            ->whereIn('unit_id', $unitIds)
            ->distinct()
            ->pluck('unit_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $occupied = Unit::query()
            ->whereIn('id', $unitIds)
            ->whereIn('id', $activeUnitIds)
            ->where('status', '!=', 'occupied')
            ->update([
                'status' => 'occupied',
            ]);

        $vacantQuery = Unit::query()
            ->whereIn('id', $unitIds)
            ->where('status', '!=', 'vacant');

        if ($activeUnitIds !== []) {
            $vacantQuery->whereNotIn('id', $activeUnitIds);
        }

        $vacant = $vacantQuery->update([
            'status' => 'vacant',
        ]);

        return $occupied + $vacant;
    }

    /**
     * Sync one or more customer statuses from their effective active contracts.
     *
     * @param  array<int, int>  $customerIds
     */
    public function syncCustomerStatuses(array $customerIds): int
    {
        $customerIds = collect($customerIds)
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($customerIds === []) {
            return 0;
        }

        $today = Carbon::today()->toDateString();
        $activeCustomerIds = $this->activeCustomerContractQuery($today)
            ->whereIn('customer_id', $customerIds)
            ->distinct()
            ->pluck('customer_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $activated = Customer::query()
            ->whereIn('id', $customerIds)
            ->whereIn('id', $activeCustomerIds)
            ->where('status', '!=', Customer::STATUS_ACTIVE)
            ->update([
                'status' => Customer::STATUS_ACTIVE,
            ]);

        $inactiveQuery = Customer::query()
            ->whereIn('id', $customerIds)
            ->where('status', '!=', Customer::STATUS_INACTIVE);

        if ($activeCustomerIds !== []) {
            $inactiveQuery->whereNotIn('id', $activeCustomerIds);
        }

        $inactivated = $inactiveQuery->update([
            'status' => Customer::STATUS_INACTIVE,
        ]);

        return $activated + $inactivated;
    }

    /**
     * Sync all customer statuses from their effective active contracts.
     */
    public function syncAllCustomerStatuses(): int
    {
        $today = Carbon::today()->toDateString();
        $activeCustomersSubquery = $this->activeCustomerIdsSubquery($today);

        $activated = Customer::query()
            ->where('status', '!=', Customer::STATUS_ACTIVE)
            ->whereIn('id', $activeCustomersSubquery)
            ->update([
                'status' => Customer::STATUS_ACTIVE,
            ]);

        $inactivated = Customer::query()
            ->where('status', '!=', Customer::STATUS_INACTIVE)
            ->whereNotIn('id', $this->activeCustomerIdsSubquery($today))
            ->update([
                'status' => Customer::STATUS_INACTIVE,
            ]);

        return $activated + $inactivated;
    }

    /**
     * Query active customer contracts for a target date.
     */
    private function activeCustomerContractQuery(string $today): Builder
    {
        return CustomerContract::query()
            ->where(function (Builder $query) use ($today) {
                $query
                    ->where(function (Builder $activeQuery) use ($today) {
                        $activeQuery
                            ->whereIn('status', self::ACTIVE_OCCUPANCY_CONTRACT_STATUSES)
                            ->where('start_date', '<=', $today)
                            ->where(function (Builder $dateQuery) use ($today) {
                                $dateQuery
                                    ->whereNull('end_date')
                                    ->orWhere('end_date', '>=', $today);
                            });
                    })
                    ->orWhere(function (Builder $terminatedQuery) use ($today) {
                        $terminatedQuery
                            ->where('status', 'terminated')
                            ->where('start_date', '<=', $today)
                            ->whereNotNull('termination_date')
                            ->where('termination_date', '>', $today);
                    });
            });
    }

    /**
     * Subquery of customer ids with at least one currently active contract.
     */
    private function activeCustomerIdsSubquery(string $today): QueryBuilder
    {
        return $this->activeCustomerContractQuery($today)
            ->select('customer_id')
            ->groupBy('customer_id')
            ->toBase();
    }

    /**
     * Normalize .
     */
    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_strtolower($value) : null;
    }

    /**
     * Normalize phone.
     */
    private function normalizePhone(?string $value): ?string
    {
        $value = preg_replace('/[^0-9+]/', '', (string) $value);

        return $value !== '' ? $value : null;
    }
}
