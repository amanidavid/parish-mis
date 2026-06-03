<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceJob extends BaseModel
{
    protected $table = 'maintenance_jobs';

    protected $casts = [
        'reported_date' => 'date:Y-m-d',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function propertyFloor(): BelongsTo
    {
        return $this->belongsTo(PropertyFloor::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(MaintenanceExpense::class);
    }
}
