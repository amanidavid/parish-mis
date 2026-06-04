<?php

namespace App\Http\Resources\Admin\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class BillingRuleResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        $rangeEnd = $this->range_end !== null ? (int) $this->range_end : null;
        $unitRangeLabel = $rangeEnd === null
            ? sprintf('%d+ units', (int) $this->range_start)
            : sprintf('%d-%d units', (int) $this->range_start, $rangeEnd);
        $priceLabel = sprintf('%s %s / month', strtoupper((string) $this->currency), number_format((int) $this->price_cents));

        return [
            'uuid' => $this->uuid,
            'range_start' => $this->range_start,
            'range_end' => $rangeEnd,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'sort_order' => $this->sort_order,
            'status' => $this->status,
            'unit_range_label' => $unitRangeLabel,
            'price_label' => $priceLabel,
            'display_name' => sprintf('%s - %s', $unitRangeLabel, $priceLabel),
            'name' => sprintf('%s - %s', $unitRangeLabel, $priceLabel),
            'billing_profile' => $this->whenLoaded('profile', fn () => [
                'uuid' => $this->profile?->uuid,
                'name' => $this->profile?->name,
                'billing_interval' => $this->profile?->billing_interval,
                'currency' => $this->profile?->currency,
                'status' => $this->profile?->status,
            ]),
            ...$this->timestamps(),
        ];
    }
}
