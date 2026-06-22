<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CustomerContract extends BaseModel
{
    protected $table = 'customer_contracts';

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'amount' => 'decimal:2',
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
