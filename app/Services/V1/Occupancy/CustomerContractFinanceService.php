<?php

namespace App\Services\V1\Occupancy;

use App\Models\Tenant\CustomerContract;
use App\Models\Tenant\CustomerContractTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CustomerContractFinanceService
{
    public function calculateEndDate(string $startDate, int $contractMonths): string
    {
        return Carbon::parse($startDate)
            ->startOfDay()
            ->addMonthsNoOverflow(max($contractMonths, 1))
            ->subDay()
            ->toDateString();
    }

    public function calculateExpectedTotal(string|float|int $unitPrice, int $contractMonths): float
    {
        return round((float) $unitPrice * max($contractMonths, 1), 2);
    }

    public function calculateUsedMonths(string $startDate, string $terminationDate, int $contractMonths): int
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $termination = Carbon::parse($terminationDate)->startOfDay();

        if ($termination->lt($start)) {
            return 0;
        }

        $usedMonths = $start->diffInMonths($termination->copy()->addDay());

        return max(0, min($usedMonths, max($contractMonths, 1)));
    }

    public function recordPayment(CustomerContract $contract, float $amount, ?string $paymentDate = null, ?string $notes = null): void
    {
        if ($amount <= 0) {
            return;
        }

        $contract->transactions()->create([
            'type' => CustomerContractTransaction::TYPE_PAYMENT,
            'amount' => $amount,
            'currency' => $contract->currency,
            'transaction_date' => $paymentDate ?: now()->toDateString(),
            'notes' => $notes,
        ]);
    }

    public function recordRefund(CustomerContract $contract, float $amount, ?string $refundDate = null, ?string $notes = null): void
    {
        if ($amount <= 0) {
            return;
        }

        $contract->transactions()->create([
            'type' => CustomerContractTransaction::TYPE_REFUND,
            'amount' => $amount,
            'currency' => $contract->currency,
            'transaction_date' => $refundDate ?: now()->toDateString(),
            'notes' => $notes,
        ]);
    }

    public function syncContractFinancials(CustomerContract $contract): CustomerContract
    {
        $grossPayments = round((float) $contract->transactions()
            ->where('type', CustomerContractTransaction::TYPE_PAYMENT)
            ->sum('amount'), 2);
        $refunds = round((float) $contract->transactions()
            ->where('type', CustomerContractTransaction::TYPE_REFUND)
            ->sum('amount'), 2);
        $expectedTotal = round((float) $contract->expected_total_amount, 2);
        $finalPayable = $expectedTotal;
        $usedMonths = null;
        $unusedMonths = null;

        if ($contract->status === 'terminated' && $contract->termination_date) {
            $usedMonths = $this->calculateUsedMonths(
                $contract->start_date->toDateString(),
                $contract->termination_date->toDateString(),
                (int) $contract->contract_months
            );
            $unusedMonths = max((int) $contract->contract_months - $usedMonths, 0);
            $finalPayable = round((float) $contract->unit_price_at_contract * $usedMonths, 2);
        }

        $netCollected = round(max($grossPayments - $refunds, 0), 2);
        $outstanding = round(max($finalPayable - $netCollected, 0), 2);
        $paymentStatus = $this->resolvePaymentStatus($finalPayable, $netCollected);

        $contract->fill([
            'amount' => $expectedTotal,
            'expected_total_amount' => $expectedTotal,
            'final_payable_amount' => $finalPayable,
            'paid_amount_total' => $grossPayments,
            'refund_amount_total' => $refunds,
            'net_collected_amount' => $netCollected,
            'outstanding_balance' => $outstanding,
            'payment_status' => $paymentStatus,
            'terminated_used_months' => $usedMonths,
            'terminated_unused_months' => $unusedMonths,
        ])->save();

        return $contract->fresh(['customer.property', 'unit.propertyFloor.property', 'documents', 'transactions']);
    }

    public function terminateContract(CustomerContract $contract, string $terminationDate, ?string $terminationReason = null): CustomerContract
    {
        return DB::transaction(function () use ($contract, $terminationDate, $terminationReason) {
            $contract->refresh()->load('transactions');

            $usedMonths = $this->calculateUsedMonths(
                $contract->start_date->toDateString(),
                $terminationDate,
                (int) $contract->contract_months
            );
            $finalPayable = round((float) $contract->unit_price_at_contract * $usedMonths, 2);
            $grossPayments = round((float) $contract->transactions
                ->where('type', CustomerContractTransaction::TYPE_PAYMENT)
                ->sum('amount'), 2);
            $existingRefunds = round((float) $contract->transactions
                ->where('type', CustomerContractTransaction::TYPE_REFUND)
                ->sum('amount'), 2);
            $expectedRefund = round(max($grossPayments - $finalPayable, 0), 2);
            $refundToCreate = round(max($expectedRefund - $existingRefunds, 0), 2);

            $contract->fill([
                'status' => 'terminated',
                'termination_date' => $terminationDate,
                'termination_reason' => $terminationReason,
            ])->save();

            if ($refundToCreate > 0) {
                $this->recordRefund(
                    $contract,
                    $refundToCreate,
                    $terminationDate,
                    'Automatic refund generated from contract termination.'
                );
            }

            return $this->syncContractFinancials($contract);
        });
    }

    private function resolvePaymentStatus(float $finalPayable, float $netCollected): string
    {
        if ($netCollected <= 0) {
            return 'unpaid';
        }

        if ($netCollected + 0.00001 < $finalPayable) {
            return 'partial';
        }

        return 'paid';
    }
}
