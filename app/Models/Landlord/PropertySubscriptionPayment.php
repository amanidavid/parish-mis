<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use App\Models\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertySubscriptionPayment extends BaseModel
{
    protected $connection = 'base';

    protected $table = 'property_subscription_payments';

    protected $casts = [
        'payment_date' => 'date',
        'coverage_starts_on' => 'date',
        'coverage_ends_on' => 'date',
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

    public function propertySubscription(): BelongsTo
    {
        return $this->belongsTo(PropertySubscription::class, 'property_subscription_id');
    }

    public function billingRule(): BelongsTo
    {
        return $this->belongsTo(BillingRule::class, 'billing_rule_id');
    }
}
