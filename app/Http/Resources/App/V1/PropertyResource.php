<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class PropertyResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        $country = $this->country ?? $this->district?->region?->country;
        $region = $this->region ?? $this->district?->region;

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'type' => $this->whenLoaded('type', fn () => $this->type ? [
                'uuid' => $this->type->uuid,
                'name' => $this->type->name,
            ] : null),
            'address_line' => $this->address_line,
            'postal_code' => $this->postal_code,
            'location' => [
                'country' => $country ? [
                    'uuid' => $country->uuid,
                    'name' => $country->name,
                    'code' => $country->code,
                ] : null,
                'region' => $region ? [
                    'uuid' => $region->uuid,
                    'name' => $region->name,
                ] : null,
                'district' => $this->district ? [
                    'uuid' => $this->district->uuid,
                    'name' => $this->district->name,
                ] : null,
                'ward' => $this->ward ? [
                    'uuid' => $this->ward->uuid,
                    'name' => $this->ward->name,
                ] : null,
            ],
            'status' => $this->status,
            'property_status' => $this->property_status ?? $this->status,
            'subscription_status' => $this->when(
                $this->resource->offsetExists('subscription_status'),
                fn () => $this->subscription_status
            ),
            'subscription_message' => $this->when(
                $this->resource->offsetExists('subscription_message'),
                fn () => $this->subscription_message
            ),
            'subscription_reason_code' => $this->when(
                $this->resource->offsetExists('subscription_reason_code'),
                fn () => $this->subscription_reason_code
            ),
            'payment_required_now' => $this->when(
                $this->resource->offsetExists('payment_required_now'),
                fn () => (bool) $this->payment_required_now
            ),
            'operations_allowed' => $this->when(
                $this->resource->offsetExists('operations_allowed'),
                fn () => (bool) $this->operations_allowed
            ),
            'operations_message' => $this->when(
                $this->resource->offsetExists('operations_message'),
                fn () => $this->operations_message
            ),
            'operations_reason_code' => $this->when(
                $this->resource->offsetExists('operations_reason_code'),
                fn () => $this->operations_reason_code
            ),
            'subscription_current_period_ends_on' => $this->when(
                $this->resource->offsetExists('subscription_current_period_ends_on'),
                fn () => $this->subscription_current_period_ends_on
            ),
            'subscription_expired_on' => $this->when(
                $this->resource->offsetExists('subscription_expired_on'),
                fn () => $this->subscription_expired_on
            ),
            'floors_count' => $this->whenCounted('floors'),
            'units_count' => $this->whenCounted('units'),
            'occupied_count' => $this->when(
                $this->resource->offsetExists('occupied_count') || $this->resource->offsetExists('contract_statuses'),
                fn () => (int) ($this->occupied_count ?? 0)
            ),
            'vacant_count' => $this->when(
                $this->resource->offsetExists('occupied_count') || $this->resource->offsetExists('contract_statuses'),
                fn () => (int) ($this->vacant_count ?? 0)
            ),
            'maintenance_count' => $this->when(
                $this->resource->offsetExists('occupied_count') || $this->resource->offsetExists('contract_statuses'),
                fn () => (int) ($this->maintenance_count ?? 0)
            ),
            'contracts_count' => $this->when(
                $this->resource->offsetExists('occupied_count') || $this->resource->offsetExists('contract_statuses'),
                fn () => (int) ($this->contracts_count ?? 0)
            ),
            'contract_statuses' => $this->when(
                $this->resource->offsetExists('contract_statuses'),
                fn () => $this->contract_statuses ?? []
            ),
            'access' => $this->when(
                $this->resource->offsetExists('access'),
                fn () => $this->access
            ),
            ...$this->timestamps(),
        ];
    }
}
