<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

abstract class ApiJsonResource extends JsonResource
{
    protected function formatTimestamp(mixed $value): ?string
    {
        return $value?->format('Y-m-d H:i:s');
    }

    protected function timestamps(): array
    {
        return [
            'created_at' => $this->formatTimestamp($this->created_at),
            'updated_at' => $this->formatTimestamp($this->updated_at),
        ];
    }
}
