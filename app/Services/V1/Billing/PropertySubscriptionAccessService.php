<?php

namespace App\Services\V1\Billing;

use App\Models\Landlord\PropertySubscription;
use App\Models\Tenant\Property;
use App\Models\Tenancy\Tenant;
use App\Services\V1\SubscriptionService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class PropertySubscriptionAccessService
{
    public function __construct(
        private WorkspacePropertyRegistryService $workspacePropertyRegistryService,
        private SubscriptionService $subscriptionService,
    ) {
    }

    public function assertPropertyAllowsOperationalMutation(Tenant $tenant, Property $property, string $moduleName = 'property operations'): void
    {
        $workspaceProperty = $this->workspacePropertyRegistryService->resolveWorkspacePropertyForModel($tenant, $property);

        if (!$workspaceProperty || $workspaceProperty->property_deleted_at) {
            throw new InvalidArgumentException('The selected property is no longer available for operational changes.');
        }

        if ($this->workspaceTrialCoversDate($tenant, Carbon::today())) {
            return;
        }

        $subscription = $workspaceProperty->subscription;

        if (!$subscription || $subscription->effectiveStatus() !== PropertySubscription::STATUS_ACTIVE) {
            throw new InvalidArgumentException(sprintf(
                'The selected property does not have an active subscription. Record a property payment before continuing with %s.',
                $moduleName
            ));
        }
    }

    public function assertContractStartDateCovered(Tenant $tenant, Property $property, CarbonInterface|string $contractStartDate): void
    {
        $workspaceProperty = $this->workspacePropertyRegistryService->resolveWorkspacePropertyForModel($tenant, $property);
        $subscription = $workspaceProperty?->subscription;
        $targetDate = Carbon::parse($contractStartDate)->startOfDay();

        if ($this->workspaceTrialCoversDate($tenant, $targetDate)) {
            return;
        }

        if (!$workspaceProperty || !$subscription || $subscription->effectiveStatus($contractStartDate) !== PropertySubscription::STATUS_ACTIVE) {
            throw new InvalidArgumentException('The selected property subscription does not cover the requested contract start date.');
        }

        $startsOn = $subscription->current_period_starts_on ? Carbon::parse($subscription->current_period_starts_on)->startOfDay() : null;
        $endsOn = $subscription->current_period_ends_on ? Carbon::parse($subscription->current_period_ends_on)->startOfDay() : null;

        if (($startsOn && $targetDate->lt($startsOn)) || ($endsOn && $targetDate->gt($endsOn))) {
            throw new InvalidArgumentException('The requested contract start date falls outside the active property subscription coverage period.');
        }
    }

    public function activeWorkspaceTrialEndsOn(Tenant $tenant, CarbonInterface|string|null $date = null): ?Carbon
    {
        $subscription = $this->subscriptionService->currentSubscription($tenant);
        $state = $this->subscriptionService->resolveSubscriptionState($subscription);
        $targetDate = $date ? Carbon::parse($date)->startOfDay() : Carbon::today();

        if (($state['status'] ?? null) !== 'trialing' || !($state['is_current_period_active'] ?? false)) {
            return null;
        }

        $trialEndsAt = $state['period_ends_at'] ?? null;

        if (!$trialEndsAt) {
            return null;
        }

        $trialEndsOn = Carbon::parse($trialEndsAt)->startOfDay();

        return $trialEndsOn->gte($targetDate) ? $trialEndsOn : null;
    }

    private function workspaceTrialCoversDate(Tenant $tenant, CarbonInterface|string $date): bool
    {
        return $this->activeWorkspaceTrialEndsOn($tenant, $date) !== null;
    }
}
