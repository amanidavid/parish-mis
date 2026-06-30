<?php

namespace App\Http\Resources\App\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerContractPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'contract_uuid' => $this->uuid,
            'contract_number' => $this->contract_number,
            'currency' => $this->currency,
            'payment_status' => $this->payment_status,
            'expected_total_amount' => (float) $this->expected_total_amount,
            'final_payable_amount' => (float) $this->final_payable_amount,
            'paid_amount_total' => (float) $this->paid_amount_total,
            'refund_amount_total' => (float) $this->refund_amount_total,
            'net_collected_amount' => (float) $this->net_collected_amount,
            'outstanding_balance' => (float) $this->outstanding_balance,
            'latest_transaction' => $this->whenLoaded('transactions', function () {
                $transaction = $this->transactions->sortByDesc('transaction_date')->first();

                if (!$transaction) {
                    return null;
                }

                return [
                    'uuid' => $transaction->uuid,
                    'type' => $transaction->type,
                    'amount' => (float) $transaction->amount,
                    'currency' => $transaction->currency,
                    'transaction_date' => optional($transaction->transaction_date)->toDateString(),
                    'notes' => $transaction->notes,
                ];
            }),
        ];
    }
}
