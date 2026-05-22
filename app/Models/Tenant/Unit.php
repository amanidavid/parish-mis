<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends BaseModel
{
    protected $table = 'units';

    public function propertyFloor(): BelongsTo
    {
        return $this->belongsTo(PropertyFloor::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(CustomerContract::class);
    }
}
