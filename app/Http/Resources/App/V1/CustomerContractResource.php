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
            'duration_months' => (int) ($this->contract_months ?? $this->durationMonths()),
            'duration_label' => $this->durationLabel(),
            'amount' => (float) $this->amount,
            'unit_price_at_contract' => (float) $this->unit_price_at_contract,
            'expected_total_amount' => (float) $this->expected_total_amount,
            'final_payable_amount' => (float) $this->final_payable_amount,
            'paid_amount_total' => (float) $this->paid_amount_total,
            'refund_amount_total' => (float) $this->refund_amount_total,
            'net_collected_amount' => (float) $this->net_collected_amount,
            'outstanding_balance' => (float) $this->outstanding_balance,
            'currency' => $this->currency,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'termination_date' => $this->termination_date?->toDateString(),
            'termination_reason' => $this->termination_reason,
            'terminated_used_months' => $this->terminated_used_months,
            'terminated_unused_months' => $this->terminated_unused_months,
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
                'monthly_rent_amount' => (float) $this->unit->monthly_rent_amount,
                'rent_currency' => $this->unit->rent_currency,
            ]),
            'documents_count' => $this->whenCounted('documents'),
            'transactions' => $this->when(!$isListView, fn () => $this->whenLoaded('transactions', function () {
                return $this->transactions->map(fn ($transaction) => [
                    'uuid' => $transaction->uuid,
                    'type' => $transaction->type,
                    'amount' => (float) $transaction->amount,
                    'currency' => $transaction->currency,
                    'transaction_date' => $transaction->transaction_date?->toDateString(),
                    'notes' => $transaction->notes,
                ])->values()->all();
            })),
            'documents' => $this->when(!$isListView, fn () => DocumentResource::collection($this->whenLoaded('documents'))),
            ...$this->timestamps(),
        ];
    }
}
