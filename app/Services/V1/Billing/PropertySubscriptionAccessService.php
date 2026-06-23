<?php

namespace App\Services\V1\Billing;

use App\Models\Landlord\PropertySubscription;
use App\Models\Landlord\WorkspaceProperty;
use App\Models\Tenant\Property;
use App\Models\Tenancy\Tenant;
use App\Services\V1\SubscriptionService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Traversable;

class PropertySubscriptionAccessService
{
    /**
     * Create a new instance.
     */
    public function __construct(
        private WorkspacePropertyRegistryService $workspacePropertyRegistryService,
        private SubscriptionService $subscriptionService,
    ) {
    }

    /**
     * Assert property allows operational mutation.
     */
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

    /**
     * Build property access summary.
     */
    public function accessSummary(Tenant $tenant, Property $property): array
    {
        $workspaceAccess = $this->subscriptionService->workspaceAccessState($tenant);
        $workspaceProperty = $this->workspacePropertyRegistryService->resolveWorkspacePropertyForModel($tenant, $property);
        $trialEndsOn = $this->activeWorkspaceTrialEndsOn($tenant);
        $subscription = $workspaceProperty?->subscription;
        $subscriptionStatus = $subscription?->effectiveStatus() ?? PropertySubscription::STATUS_UNSUBSCRIBED;

        $propertySubscriptionAccess = [
            'allowed' => false,
            'reason_code' => 'property_subscription_required',
            'message' => 'Property subscription access is required.',
            'subscription_status' => $subscriptionStatus,
            'payment_required_now' => true,
            'trial_ends_on' => $trialEndsOn?->toDateString(),
            'current_period_ends_on' => $subscription?->current_period_ends_on?->toDateString(),
            'expired_on' => $subscription?->expired_on?->toDateString(),
        ];

        if (!$workspaceProperty || $workspaceProperty->property_deleted_at) {
            $propertySubscriptionAccess = [
                ...$propertySubscriptionAccess,
                'reason_code' => 'property_unavailable',
                'message' => 'This property is no longer available.',
                'subscription_status' => null,
                'payment_required_now' => false,
            ];
        } elseif ($trialEndsOn !== null) {
            $propertySubscriptionAccess = [
                'allowed' => true,
                'reason_code' => 'workspace_trial_active',
                'message' => 'Workspace trial access is active. Property subscription payment will be required after the trial ends.',
                'subscription_status' => $subscriptionStatus,
                'payment_required_now' => false,
                'trial_ends_on' => $trialEndsOn->toDateString(),
                'current_period_ends_on' => $subscription?->current_period_ends_on?->toDateString(),
                'expired_on' => $subscription?->expired_on?->toDateString(),
            ];
        } elseif ($subscription && $subscriptionStatus === PropertySubscription::STATUS_ACTIVE) {
            $propertySubscriptionAccess = [
                'allowed' => true,
                'reason_code' => null,
                'message' => null,
                'subscription_status' => $subscriptionStatus,
                'payment_required_now' => false,
                'trial_ends_on' => null,
                'current_period_ends_on' => $subscription->current_period_ends_on?->toDateString(),
                'expired_on' => $subscription->expired_on?->toDateString(),
            ];
        } elseif ($subscriptionStatus === PropertySubscription::STATUS_EXPIRED) {
            $propertySubscriptionAccess = [
                ...$propertySubscriptionAccess,
                'reason_code' => 'property_subscription_expired',
                'message' => 'Property subscription access is required. The current property subscription has expired.',
            ];
        }

        $operationsAllowed = (bool) ($workspaceAccess['inventory_changes_allowed'] ?? false)
            && (bool) ($propertySubscriptionAccess['allowed'] ?? false);

        $operationsReasonCode = $workspaceAccess['inventory_changes_allowed'] ?? false
            ? ($propertySubscriptionAccess['reason_code'] ?? null)
            : 'workspace_access_required';
        $operationsMessage = $workspaceAccess['inventory_changes_allowed'] ?? false
            ? ($propertySubscriptionAccess['message'] ?? null)
            : ($workspaceAccess['message'] ?? 'Workspace access is restricted at the moment.');

        return [
            'workspace' => [
                'state' => $workspaceAccess['state'] ?? null,
                'message' => $workspaceAccess['message'] ?? null,
                'inventory_changes_allowed' => (bool) ($workspaceAccess['inventory_changes_allowed'] ?? false),
            ],
            'property_subscription' => $propertySubscriptionAccess,
            'operations' => [
                'allowed' => $operationsAllowed,
                'reason_code' => $operationsReasonCode,
                'message' => $operationsMessage,
            ],
        ];
    }

    /**
     * Build subscription snapshot map for property list results.
     */
    public function propertyListSnapshotMap(Tenant $tenant, iterable $properties): array
    {
        $propertyItems = $properties instanceof Traversable
            ? iterator_to_array($properties, false)
            : (is_array($properties) ? $properties : []);
        $propertyIds = collect($propertyItems)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($propertyIds === []) {
            return [];
        }

        $this->workspacePropertyRegistryService->syncPropertyIds($tenant, $propertyIds);

        $workspaceAccess = $this->subscriptionService->workspaceAccessState($tenant);
        $trialEndsOn = $this->activeWorkspaceTrialEndsOn($tenant);
        $propertyUuids = collect($propertyItems)
            ->pluck('uuid')
            ->filter()
            ->values()
            ->all();

        $workspaceProperties = WorkspaceProperty::query()
            ->with([
                'subscription:id,workspace_property_id,status,current_period_ends_on,expired_on',
            ])
            ->where('tenant_id', $tenant->id)
            ->whereIn('property_uuid', $propertyUuids)
            ->get()
            ->keyBy('property_uuid');

        return collect($propertyItems)
            ->mapWithKeys(function (Property $property) use ($workspaceAccess, $trialEndsOn, $workspaceProperties) {
                /** @var WorkspaceProperty|null $workspaceProperty */
                $workspaceProperty = $workspaceProperties->get($property->uuid);
                $subscription = $workspaceProperty?->subscription;
                $subscriptionStatus = $subscription?->effectiveStatus() ?? PropertySubscription::STATUS_UNSUBSCRIBED;
                $propertyAccess = $this->buildPropertySubscriptionAccessPayload(
                    $workspaceProperty,
                    $subscription,
                    $subscriptionStatus,
                    $trialEndsOn
                );
                $operationsAllowed = (bool) ($workspaceAccess['inventory_changes_allowed'] ?? false)
                    && (bool) ($propertyAccess['allowed'] ?? false);
                $operationsReasonCode = $workspaceAccess['inventory_changes_allowed'] ?? false
                    ? ($propertyAccess['reason_code'] ?? null)
                    : 'workspace_access_required';
                $operationsMessage = $workspaceAccess['inventory_changes_allowed'] ?? false
                    ? ($propertyAccess['message'] ?? null)
                    : ($workspaceAccess['message'] ?? 'Workspace access is restricted at the moment.');

                return [
                    $property->uuid => [
                        'property_status' => $property->status,
                        'subscription_status' => $propertyAccess['subscription_status'] ?? PropertySubscription::STATUS_UNSUBSCRIBED,
                        'subscription_message' => $propertyAccess['message'] ?? null,
                        'subscription_reason_code' => $propertyAccess['reason_code'] ?? null,
                        'payment_required_now' => (bool) ($propertyAccess['payment_required_now'] ?? false),
                        'operations_allowed' => $operationsAllowed,
                        'operations_message' => $operationsMessage,
                        'operations_reason_code' => $operationsReasonCode,
                        'current_period_ends_on' => $propertyAccess['current_period_ends_on'] ?? null,
                        'expired_on' => $propertyAccess['expired_on'] ?? null,
                    ],
                ];
            })
            ->all();
    }

    /**
     * Assert contract start date covered.
     */
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

    /**
     * Handle active workspace trial ends on.
     */
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

    /**
     * Workspace trial covers date.
     */
    private function workspaceTrialCoversDate(Tenant $tenant, CarbonInterface|string $date): bool
    {
        return $this->activeWorkspaceTrialEndsOn($tenant, $date) !== null;
    }

    /**
     * Build property subscription access payload.
     */
    private function buildPropertySubscriptionAccessPayload(
        ?WorkspaceProperty $workspaceProperty,
        ?PropertySubscription $subscription,
        string $subscriptionStatus,
        ?Carbon $trialEndsOn,
    ): array {
        $payload = [
            'allowed' => false,
            'reason_code' => 'property_subscription_required',
            'message' => 'Property subscription access is required.',
            'subscription_status' => $subscriptionStatus,
            'payment_required_now' => true,
            'trial_ends_on' => $trialEndsOn?->toDateString(),
            'current_period_ends_on' => $subscription?->current_period_ends_on?->toDateString(),
            'expired_on' => $subscription?->expired_on?->toDateString(),
        ];

        if (!$workspaceProperty || $workspaceProperty->property_deleted_at) {
            return [
                ...$payload,
                'reason_code' => 'property_unavailable',
                'message' => 'This property is no longer available.',
                'subscription_status' => null,
                'payment_required_now' => false,
            ];
        }

        if ($trialEndsOn !== null) {
            return [
                'allowed' => true,
                'reason_code' => 'workspace_trial_active',
                'message' => 'Workspace trial access is active. Property subscription payment will be required after the trial ends.',
                'subscription_status' => $subscriptionStatus,
                'payment_required_now' => false,
                'trial_ends_on' => $trialEndsOn->toDateString(),
                'current_period_ends_on' => $subscription?->current_period_ends_on?->toDateString(),
                'expired_on' => $subscription?->expired_on?->toDateString(),
            ];
        }

        if ($subscription && $subscriptionStatus === PropertySubscription::STATUS_ACTIVE) {
            return [
                'allowed' => true,
                'reason_code' => null,
                'message' => null,
                'subscription_status' => $subscriptionStatus,
                'payment_required_now' => false,
                'trial_ends_on' => null,
                'current_period_ends_on' => $subscription->current_period_ends_on?->toDateString(),
                'expired_on' => $subscription->expired_on?->toDateString(),
            ];
        }

        if ($subscriptionStatus === PropertySubscription::STATUS_EXPIRED) {
            return [
                ...$payload,
                'reason_code' => 'property_subscription_expired',
                'message' => 'Property subscription access is required. The current property subscription has expired.',
            ];
        }

        return $payload;
    }
}
