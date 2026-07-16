<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyInvoiceItem extends BaseModel
{
    protected $connection = 'base';

    protected $table = 'property_invoice_items';

    protected $casts = [
        'meta' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PropertyInvoice::class, 'property_invoice_id');
    }
}
