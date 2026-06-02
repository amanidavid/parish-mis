<?php

namespace App\Http\Resources;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class ApiJsonResource extends JsonResource
{
    protected function formatTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_string($value)) {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        }

        return null;
    }

    protected function timestamps(): array
    {
        return [
            'created_at' => $this->formatTimestamp($this->created_at),
            'updated_at' => $this->formatTimestamp($this->updated_at),
        ];
    }
}
