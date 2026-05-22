<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ward extends BaseModel
{
    protected $table = 'wards';

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
