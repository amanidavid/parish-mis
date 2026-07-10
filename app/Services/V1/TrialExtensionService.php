<?php

namespace App\Services\V1;

use App\Models\Landlord\Subscription;
use App\Models\Landlord\SubscriptionTrialExtension;
use App\Models\Tenancy\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TrialExtensionService
{
    public function __construct(
        private SubscriptionService $subscriptionService,
    ) {
    }

    public function extendTrial(Tenant $tenant, array $payload, ?object $actor = null): array
    {
        return DB::connection('base')->transaction(function () use ($tenant, $payload, $actor) {
            $subscription = Subscription::query()
                ->select([
                    'id',
                    'uuid',
                    'tenant_id',
                    'status',
                    'starts_at',
                    'ends_at',
                    'trial_ends_at',
                    'created_at',
                    'updated_at',
                ])
                ->where('tenant_id', $tenant->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                throw new InvalidArgumentException('No active subscription record was found for this workspace.');
            }

            $subscriptionState = $this->subscriptionService->resolveSubscriptionState($subscription);
            if ($subscription->status !== 'trialing' || ($subscriptionState['status'] ?? null) !== 'trialing') {
                throw new InvalidArgumentException('Free trial extension is allowed only for workspaces currently in trial period.');
            }

            if (!$subscription->trial_ends_at) {
                throw new InvalidArgumentException('This workspace trial end date could not be resolved.');
            }

            $extraDays = (int) $payload['days'];
            $oldTrialEndsAt = Carbon::parse($subscription->trial_ends_at->format('Y-m-d H:i:s'));
            $newTrialEndsAt = $oldTrialEndsAt->copy()->addDays($extraDays);

            $subscription->forceFill([
                'trial_ends_at' => $newTrialEndsAt,
                'ends_at' => $newTrialEndsAt,
            ])->save();

            $extension = SubscriptionTrialExtension::query()->create([
                'subscription_id' => $subscription->id,
                'tenant_id' => $tenant->id,
                'extended_by_user_id' => $this->resolveActorUserId($actor),
                'days' => $extraDays,
                'old_trial_ends_at' => $oldTrialEndsAt,
                'new_trial_ends_at' => $newTrialEndsAt,
                'reason' => $payload['reason'] ?? null,
            ]);

            return $this->formatExtension($extension);
        });
    }

    public function formatExtension(SubscriptionTrialExtension $extension): array
    {
        return [
            'uuid' => $extension->uuid,
            'extra_days' => (int) $extension->days,
            'reason' => $extension->reason,
            'old_trial_ends_at' => $extension->old_trial_ends_at?->format('Y-m-d H:i:s'),
            'new_trial_ends_at' => $extension->new_trial_ends_at?->format('Y-m-d H:i:s'),
            'extended_by_user_id' => $extension->extended_by_user_id,
            'extended_at' => $extension->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function resolveActorUserId(?object $actor): ?int
    {
        $actorId = data_get($actor, 'id');

        return is_numeric($actorId) ? (int) $actorId : null;
    }
}
