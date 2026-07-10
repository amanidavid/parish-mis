<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyPropertyExpense extends BaseModel
{
    protected $table = 'daily_property_expenses';

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date:Y-m-d',
        'currency' => 'string',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
