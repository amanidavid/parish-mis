<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class CustomerContractResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        $isListView = $request->route()?->getActionMethod() === 'index';

        return [
            'uuid' => $this->uuid,
            'contract_number' => $this->contract_number,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'duration_months' => $this->durationMonths(),
            'duration_label' => $this->durationLabel(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'notes' => $this->notes,
            'customer' => $this->whenLoaded('customer', fn () => [
                'uuid' => $this->customer->uuid,
                'display_name' => $this->customer->display_name,
                'customer_type' => $this->customer->customer_type,
                'property_uuid' => $this->customer->property?->uuid,
            ]),
            'unit' => $this->whenLoaded('unit', fn () => [
                'uuid' => $this->unit->uuid,
                'unit_number' => $this->unit->unit_number,
                'property_floor' => $this->unit->propertyFloor ? [
                    'uuid' => $this->unit->propertyFloor->uuid,
                    'name' => $this->unit->propertyFloor->name,
                    'floor_number' => $this->unit->propertyFloor->floor_number,
                ] : null,
                'property' => $this->unit->propertyFloor?->property ? [
                    'uuid' => $this->unit->propertyFloor->property->uuid,
                    'name' => $this->unit->propertyFloor->property->name,
                ] : null,
            ]),
            'documents_count' => $this->whenCounted('documents'),
            'documents' => $this->when(!$isListView, fn () => DocumentResource::collection($this->whenLoaded('documents'))),
            ...$this->timestamps(),
        ];
    }
}
