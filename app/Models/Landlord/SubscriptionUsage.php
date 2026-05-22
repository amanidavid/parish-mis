<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;

class SubscriptionUsage extends BaseModel
{
    protected $connection = 'base';

    protected $table = 'subscription_usages';

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'meta' => 'array',
        'billed' => 'boolean',
    ];
}
