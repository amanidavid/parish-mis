<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends BaseModel
{
    protected $table = 'customers';

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function businessDetail(): HasOne
    {
        return $this->hasOne(CustomerBusinessDetail::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(CustomerContract::class);
    }
}
