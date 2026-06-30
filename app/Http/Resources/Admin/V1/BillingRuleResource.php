<?php

namespace App\Http\Resources\Admin\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class BillingRuleResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        $formattedPrice = $this->formatMoneyFromCents((int) $this->unit_price_cents, $this->currency);
        $ruleName = 'Default unit price';

        return [
            'uuid' => $this->uuid,
            'unit_price_cents' => (int) $this->unit_price_cents,
            'currency' => $this->currency,
            'unit_price_formatted' => $formattedPrice,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'status' => $this->status,
            'display_name' => sprintf('%s - %s per unit / month', $ruleName, $formattedPrice),
            'name' => sprintf('%s - %s per unit / month', $ruleName, $formattedPrice),
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
