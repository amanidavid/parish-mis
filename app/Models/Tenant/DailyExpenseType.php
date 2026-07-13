<?php

namespace App\Models\Tenant;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyExpenseType extends BaseModel
{
    protected $table = 'daily_expense_types';

    public function dailyExpenses(): HasMany
    {
        return $this->hasMany(DailyPropertyExpense::class, 'expense_type_id');
    }
}
