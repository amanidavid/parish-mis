<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends BaseModel
{
    public const STATUS_VACANT = 'vacant';
    public const STATUS_OCCUPIED = 'occupied';
    public const STATUS_MAINTENANCE = 'maintenance';

    public const MANUAL_STATUSES = [
        self::STATUS_VACANT,
        self::STATUS_MAINTENANCE,
    ];

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
