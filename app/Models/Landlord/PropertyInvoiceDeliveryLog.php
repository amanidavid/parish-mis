<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyInvoiceDeliveryLog extends BaseModel
{
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public const KIND_PAID_EMAIL = 'paid_invoice_email';
    public const KIND_REMINDER_EMAIL = 'invoice_reminder_email';
    public const KIND_REMINDER_SMS = 'invoice_reminder_sms';

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
