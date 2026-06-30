<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CustomerContract extends BaseModel
{
    protected $table = 'customer_contracts';

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'amount' => 'decimal:2',
        'unit_price_at_contract' => 'decimal:2',
        'expected_total_amount' => 'decimal:2',
        'final_payable_amount' => 'decimal:2',
        'paid_amount_total' => 'decimal:2',
        'refund_amount_total' => 'decimal:2',
        'net_collected_amount' => 'decimal:2',
        'outstanding_balance' => 'decimal:2',
        'termination_date' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CustomerContractTransaction::class, 'customer_contract_id');
    }

    /**
     * Get duration months.
     */
    public function durationMonths(): ?int
    {
        if (!$this->start_date || !$this->end_date) {
            return null;
        }

        $startDate = Carbon::parse($this->start_date)->startOfDay();
        $endDate = Carbon::parse($this->end_date)->startOfDay();

        if ($endDate->lt($startDate)) {
            return null;
        }

        return $startDate->diffInMonths($endDate->copy()->addDay());
    }

    /**
     * Get duration label.
     */
    public function durationLabel(): ?string
    {
        $months = $this->durationMonths();

        if ($months === null) {
            return null;
        }

        return $months === 1 ? '1 month' : sprintf('%d months', $months);
    }
}
