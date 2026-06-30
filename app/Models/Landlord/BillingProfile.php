<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingProfile extends BaseModel
{
    protected $connection = 'base';

    protected $table = 'billing_profiles';

    protected $casts = [
        'is_default' => 'boolean',
        'meta' => 'array',
    ];

    public function rules(): HasMany
    {
        return $this->hasMany(BillingRule::class)->orderByDesc('effective_from')->orderByDesc('created_at');
    }
}
