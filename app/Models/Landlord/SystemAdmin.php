<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;

class SystemAdmin extends BaseModel
{
    protected $connection = 'base';

    protected $table = 'system_admins';

    protected $casts = [
        'scopes' => 'array',
        'super' => 'boolean',
    ];
}
