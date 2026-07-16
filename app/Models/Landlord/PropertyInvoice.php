<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use App\Models\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyInvoice extends BaseModel
{
    public const STATUS_PAID = 'paid';
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_OVERDUE = 'overdue';

    protected $connection = 'base';

    protected $table = 'property_invoices';

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'period_starts_on' => 'date',
        'period_ends_on' => 'date',
        'sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'meta' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function workspaceProperty(): BelongsTo
    {
        return $this->belongsTo(WorkspaceProperty::class, 'workspace_property_id');
    }

    public function propertySubscription(): BelongsTo
    {
        return $this->belongsTo(PropertySubscription::class, 'property_subscription_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PropertyInvoiceItem::class, 'property_invoice_id');
    }

    public function deliveryLogs(): HasMany
    {
        return $this->hasMany(PropertyInvoiceDeliveryLog::class, 'property_invoice_id');
    }
}
