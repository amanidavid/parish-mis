<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;

class Tenant extends BaseModel
{
    protected $connection = 'base';
    protected $table = 'tenants';
}
