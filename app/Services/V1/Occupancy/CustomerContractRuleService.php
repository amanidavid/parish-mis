<?php

namespace App\Services\V1\Occupancy;

use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerContract;
use App\Models\Tenant\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class CustomerContractRuleService
{
    public const OPEN_ENDED_CONTRACT_END_DATE = '9999-12-31';
    public const ACTIVE_OCCUPANCY_CONTRACT_STATUSES = ['active', 'renewed'];

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
            ->whereDate('start_date', '<=', $endDate ?? self::OPEN_ENDED_CONTRACT_END_DATE)
            ->where(function (Builder $innerQuery) use ($startDate) {
                $innerQuery
                    ->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $startDate);
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

        $hasActiveOccupancy = CustomerContract::query()
            ->where('unit_id', $unitId)
            ->whereIn('status', self::ACTIVE_OCCUPANCY_CONTRACT_STATUSES)
            ->whereDate('start_date', '<=', $today)
            ->where(function (Builder $query) use ($today) {
                $query
                    ->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $today);
            })
            ->exists();

        Unit::query()
            ->whereKey($unitId)
            ->update([
                'status' => $hasActiveOccupancy ? 'occupied' : 'vacant',
            ]);
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
