<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBusinessDetail extends BaseModel
{
    protected $table = 'customer_business_details';

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
