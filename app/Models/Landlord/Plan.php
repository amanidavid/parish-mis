<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;

class Plan extends BaseModel
{
    protected $connection = 'base';

    protected $table = 'plans';

    protected $casts = [
        'features' => 'array',
    ];
}
