<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceExpense extends BaseModel
{
    protected $table = 'maintenance_expenses';

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date:Y-m-d',
    ];

    public function maintenanceJob(): BelongsTo
    {
        return $this->belongsTo(MaintenanceJob::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
