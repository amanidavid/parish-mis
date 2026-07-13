<?php

namespace App\Http\Resources\App\V1\Maintenance;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class DailyExpenseTypeResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'daily_expenses_count' => $this->whenCounted('dailyExpenses'),
            ...$this->timestamps(),
        ];
    }
}
