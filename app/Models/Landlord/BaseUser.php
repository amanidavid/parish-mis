<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BaseUser extends BaseModel implements Authenticatable
{
    use AuthenticatableTrait;
    use HasFactory;

    protected $connection = 'base';
    protected $table = 'users';
    protected $hidden = ['password'];

    protected $casts = [
        'last_login_at' => 'datetime',
        'meta' => 'array',
    ];
}
