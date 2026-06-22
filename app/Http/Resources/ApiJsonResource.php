<?php

namespace App\Http\Resources;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class ApiJsonResource extends JsonResource
{
    protected function formatTimestamp(mixed $value, ?string $timezone = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            $date = $value instanceof Carbon ? $value : Carbon::instance($value);

            return $timezone
                ? $date->copy()->timezone($timezone)->format('Y-m-d H:i:s')
                : $date->format('Y-m-d H:i:s');
        }

        if (is_string($value)) {
            $date = Carbon::parse($value);

            return $timezone
                ? $date->copy()->timezone($timezone)->format('Y-m-d H:i:s')
                : $date->format('Y-m-d H:i:s');
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
