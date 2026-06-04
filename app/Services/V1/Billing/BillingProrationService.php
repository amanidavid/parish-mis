<?php

namespace App\Services\V1\Billing;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class BillingProrationService
{
    public function calculateCurrentCycleAdjustment(
        ?CarbonInterface $periodStartsAt,
        ?CarbonInterface $periodEndsAt,
        bool $isCurrentPeriodActive,
        bool $isStatusEligible,
        CarbonInterface|string|null $effectiveAt,
        int $currentAmount,
        int $newAmount,
    ): array {
        if (!$isCurrentPeriodActive
            || !$isStatusEligible
            || !$periodStartsAt instanceof CarbonInterface
            || !$periodEndsAt instanceof CarbonInterface) {
            return $this->emptyAdjustment();
        }

        $periodStart = Carbon::parse($periodStartsAt)->startOfDay();
        $periodEnd = Carbon::parse($periodEndsAt)->endOfDay();
        $anchor = $effectiveAt
            ? Carbon::parse($effectiveAt)
            : now();
        $anchor = $anchor->lt($periodStart) ? $periodStart : $anchor;

        if ($anchor->gt($periodEnd)) {
            throw new InvalidArgumentException('Immediate proration must be applied within the current billing cycle.');
        }

        $totalCycleDays = $periodStart->diffInDays($periodEnd->copy()->startOfDay()) + 1;
        $remainingCycleDays = $anchor->copy()->startOfDay()->diffInDays($periodEnd->copy()->startOfDay()) + 1;
        $adjustment = (int) round((($newAmount - $currentAmount) * $remainingCycleDays) / max($totalCycleDays, 1));

        return [
            'applies' => true,
            'total_cycle_days' => $totalCycleDays,
            'remaining_cycle_days' => $remainingCycleDays,
            'prorated_adjustment_cents' => $adjustment,
            'adjustment_type' => $adjustment > 0
                ? 'charge'
                : ($adjustment < 0 ? 'credit' : 'none'),
        ];
    }

    public function emptyAdjustment(): array
    {
        return [
            'applies' => false,
            'total_cycle_days' => 0,
            'remaining_cycle_days' => 0,
            'prorated_adjustment_cents' => 0,
            'adjustment_type' => 'none',
        ];
    }
}
