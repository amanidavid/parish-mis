<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends BaseModel
{
    protected $table = 'countries';

    protected $casts = [
        'currency_code' => 'string',
    ];

    public function regions(): HasMany
    {
        return $this->hasMany(Region::class);
    }
}
