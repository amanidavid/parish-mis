<?php

namespace App\Http\Resources\Admin\V1\Billing;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class AutomationTaskRunResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'started_at' => $this->formatTimestamp($this->started_at),
            'finished_at' => $this->formatTimestamp($this->finished_at),
            'rows_affected' => (int) $this->rows_affected,
            'message' => $this->message,
            'meta' => $this->meta,
            ...$this->timestamps(),
        ];
    }
}
