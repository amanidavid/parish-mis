<?php

namespace App\Services\V1\Concerns;

use Throwable;

trait DispatchesAlertsWithRetry
{
    /**
     * Dispatch an alert with a small retry window for transient failures.
     *
     * @return array{status: string, error: ?string, attempts: int}
     */
    private function dispatchAlertWithRetry(callable $callback, string $configKey): array
    {
        $maxAttempts = max((int) config($configKey.'.retry.max_attempts', 3), 1);
        $delayMs = max((int) config($configKey.'.retry.delay_ms', 500), 0);
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $callback();

                return [
                    'status' => 'success',
                    'error' => null,
                    'attempts' => $attempt,
                ];
            } catch (Throwable $exception) {
                $lastException = $exception;

                if ($attempt < $maxAttempts && $delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        return [
            'status' => 'failed',
            'error' => $lastException?->getMessage(),
            'attempts' => $maxAttempts,
        ];
    }
}
