<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use App\Models\Tenancy\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class PropertySubscription extends BaseModel
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    protected $connection = 'base';

    protected $table = 'property_subscriptions';

    protected $casts = [
        'current_period_starts_on' => 'date',
        'current_period_ends_on' => 'date',
        'last_paid_on' => 'date',
        'activated_on' => 'date',
        'expired_on' => 'date',
        'meta' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function workspaceProperty(): BelongsTo
    {
        return $this->belongsTo(WorkspaceProperty::class, 'workspace_property_id');
    }

    public function billingRule(): BelongsTo
    {
        return $this->belongsTo(BillingRule::class, 'billing_rule_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PropertySubscriptionPayment::class, 'property_subscription_id');
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(PropertySubscriptionPayment::class, 'property_subscription_id')->latestOfMany();
    }

    public function effectiveStatus(CarbonInterface|string|null $asOf = null): string
    {
        $date = $asOf ? Carbon::parse($asOf)->startOfDay() : Carbon::today();

        if ($this->status === self::STATUS_ACTIVE) {
            if ($this->current_period_ends_on && Carbon::parse($this->current_period_ends_on)->lt($date)) {
                return self::STATUS_EXPIRED;
            }

            return self::STATUS_ACTIVE;
        }

        return $this->status ?: self::STATUS_UNSUBSCRIBED;
    }

    public function coversDate(CarbonInterface|string $date): bool
    {
        if ($this->effectiveStatus($date) !== self::STATUS_ACTIVE) {
            return false;
        }

        $targetDate = Carbon::parse($date)->startOfDay();
        $startsOn = $this->current_period_starts_on ? Carbon::parse($this->current_period_starts_on)->startOfDay() : null;
        $endsOn = $this->current_period_ends_on ? Carbon::parse($this->current_period_ends_on)->startOfDay() : null;

        if ($startsOn && $targetDate->lt($startsOn)) {
            return false;
        }

        if ($endsOn && $targetDate->gt($endsOn)) {
            return false;
        }

        return true;
    }
}
