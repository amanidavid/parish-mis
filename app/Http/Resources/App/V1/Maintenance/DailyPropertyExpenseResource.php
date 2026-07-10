<?php

namespace App\Http\Resources\App\V1\Maintenance;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class DailyPropertyExpenseResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'currency' => $this->normalizeCurrency($this->currency),
            'expense_date' => $this->expense_date?->format('Y-m-d'),
            'property' => $this->whenLoaded('property', fn () => $this->property ? [
                'uuid' => $this->property->uuid,
                'name' => $this->property->name,
                'currency' => $this->property->currency,
            ] : null),
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy ? [
                'uuid' => $this->recordedBy->uuid,
                'name' => $this->recordedBy->name,
                'email' => $this->recordedBy->email,
            ] : null),
            ...$this->timestamps(),
        ];
    }
}
