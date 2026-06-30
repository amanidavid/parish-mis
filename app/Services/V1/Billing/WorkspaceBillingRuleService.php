<?php

namespace App\Services\V1\Billing;

use App\Models\Landlord\BillingProfile;
use App\Models\Landlord\BillingRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WorkspaceBillingRuleService
{
    /**
     * Resolve the active default billing rule for the given date.
     */
    public function activeRule(CarbonInterface|string|null $effectiveOn = null): ?BillingRule
    {
        $effectiveDate = $effectiveOn instanceof CarbonInterface
            ? Carbon::parse($effectiveOn->format('Y-m-d'))->toDateString()
            : Carbon::parse($effectiveOn ?: now()->toDateString())->toDateString();

        return BillingRule::query()
            ->where('status', 'active')
            ->where('effective_from', '<=', $effectiveDate)
            ->where(function ($query) use ($effectiveDate) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $effectiveDate);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Resolve the active rule or fail with a business-friendly error.
     */
    public function requireActiveRule(CarbonInterface|string|null $effectiveOn = null): BillingRule
    {
        $rule = $this->activeRule($effectiveOn);

        if (!$rule) {
            throw new InvalidArgumentException(
                'Workspace default unit price has not been configured yet. Set the unit price before recording or previewing property payments.'
            );
        }

        return $rule;
    }

    /**
     * Calculate one property's monthly charge from current unit count.
     */
    public function calculateMonthlyCharge(int $registeredUnits, ?BillingRule $rule): int
    {
        if (!$rule || $registeredUnits <= 0) {
            return 0;
        }

        return $registeredUnits * (int) $rule->unit_price_cents;
    }

    /**
     * Shape a billing rule for stable API responses.
     */
    public function formatRule(?BillingRule $rule): ?array
    {
        if (!$rule) {
            return null;
        }

        return [
            'uuid' => $rule->uuid,
            'unit_price_cents' => (int) $rule->unit_price_cents,
            'currency' => $rule->currency,
            'effective_from' => $rule->effective_from?->toDateString(),
            'effective_to' => $rule->effective_to?->toDateString(),
            'status' => $rule->status,
            'scope' => 'global_default',
        ];
    }

    /**
     * Preview a default unit-price change.
     */
    public function previewRuleChange(int $unitPriceCents, ?string $effectiveFrom = null, ?string $currency = null): array
    {
        $effectiveDate = Carbon::parse($effectiveFrom ?: now()->toDateString())->startOfDay();
        $currentRule = $this->activeRule($effectiveDate);

        return [
            'effective_from' => $effectiveDate->toDateString(),
            'current_billing_rule' => $this->formatRule($currentRule),
            'new_billing_rule' => [
                'unit_price_cents' => $unitPriceCents,
                'currency' => strtoupper($currency ?: $currentRule?->currency ?: 'TZS'),
                'effective_from' => $effectiveDate->toDateString(),
                'effective_to' => null,
                'status' => 'active',
                'scope' => 'global_default',
            ],
            'pricing' => [
                'current_unit_price_cents' => (int) ($currentRule?->unit_price_cents ?? 0),
                'new_unit_price_cents' => $unitPriceCents,
                'delta_unit_price_cents' => $unitPriceCents - (int) ($currentRule?->unit_price_cents ?? 0),
            ],
        ];
    }

    /**
     * Create a new active default unit-price rule and retire old active rules.
     */
    public function applyRuleChange(int $unitPriceCents, ?string $effectiveFrom = null, ?string $currency = null): BillingRule
    {
        $effectiveDate = Carbon::parse($effectiveFrom ?: now()->toDateString())->startOfDay();
        $billingProfileId = BillingProfile::query()
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('id');

        if (!$billingProfileId) {
            throw new InvalidArgumentException('A default billing profile record is required before saving workspace unit prices.');
        }

        return DB::connection('base')->transaction(function () use ($unitPriceCents, $effectiveDate, $currency, $billingProfileId) {
            BillingRule::query()
                ->where('status', 'active')
                ->update([
                    'status' => 'inactive',
                    'effective_to' => $effectiveDate->copy()->subDay()->toDateString(),
                    'updated_at' => now(),
                ]);

            return BillingRule::query()->create([
                'uuid' => (string) Str::uuid(),
                'billing_profile_id' => $billingProfileId,
                'unit_price_cents' => $unitPriceCents,
                'currency' => strtoupper($currency ?: 'TZS'),
                'effective_from' => $effectiveDate->toDateString(),
                'effective_to' => null,
                'status' => 'active',
            ]);
        });
    }
}
