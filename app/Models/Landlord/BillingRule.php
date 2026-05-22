<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingRule extends BaseModel
{
    protected $connection = 'base';

    protected $table = 'billing_rules';

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'meta' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(BillingProfile::class, 'billing_profile_id');
    }
}
