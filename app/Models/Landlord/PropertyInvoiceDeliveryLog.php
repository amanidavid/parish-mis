<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyInvoiceDeliveryLog extends BaseModel
{
    protected $connection = 'base';

    protected $table = 'property_invoice_delivery_logs';

    protected $casts = [
        'last_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
        'meta' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PropertyInvoice::class, 'property_invoice_id');
    }
}
