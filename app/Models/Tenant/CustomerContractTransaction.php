<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerContractTransaction extends BaseModel
{
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_REFUND = 'refund';

    protected $table = 'customer_contract_transactions';

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(CustomerContract::class, 'customer_contract_id');
    }
}
