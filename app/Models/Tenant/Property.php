<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Property extends BaseModel
{
    protected $table = 'properties';

    public function type(): BelongsTo
    {
        return $this->belongsTo(PropertyType::class, 'type_id');
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function floors(): HasMany
    {
        return $this->hasMany(PropertyFloor::class);
    }

    public function units(): HasManyThrough
    {
        return $this->hasManyThrough(Unit::class, PropertyFloor::class, 'property_id', 'property_floor_id');
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(StaffPropertyAssignment::class);
    }
}
