<?php

namespace App\Http\Resources;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class ApiJsonResource extends JsonResource
{
    protected function normalizeCurrency(?string $currency, string $fallback = 'TZS'): string
    {
        $currency = strtoupper(trim((string) $currency));

        return $currency !== '' ? $currency : $fallback;
    }

    protected function formatMoneyAmount(int|float|string|null $amount, ?string $currency = 'TZS', int $decimals = 0): string
    {
        return sprintf(
            '%s %s',
            $this->normalizeCurrency($currency),
            number_format((float) ($amount ?? 0), $decimals)
        );
    }

    protected function formatMoneyFromCents(int|float|string|null $amountCents, ?string $currency = 'TZS', int $decimals = 0): string
    {
        return $this->formatMoneyAmount($amountCents, $currency, $decimals);
    }

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
