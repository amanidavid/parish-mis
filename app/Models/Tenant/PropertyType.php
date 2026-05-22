<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyType extends BaseModel
{
    protected $table = 'property_types';

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'type_id');
    }
}
