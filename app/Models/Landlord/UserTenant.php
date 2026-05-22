<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;

class UserTenant extends BaseModel
{
    protected $connection = 'base';
    protected $table = 'user_tenants';
}
