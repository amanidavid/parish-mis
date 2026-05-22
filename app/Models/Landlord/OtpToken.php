<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;

class OtpToken extends BaseModel
{
    protected $connection = 'base';
    protected $table = 'otp_tokens';
    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
