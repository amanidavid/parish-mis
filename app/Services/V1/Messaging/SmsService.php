<?php

namespace App\Services\V1\Messaging;

use App\Services\V1\Messaging\Exceptions\SmsDeliveryException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmsService
{
    public function __construct(
        private HttpFactory $http,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.sms.enabled', false);
    }

    public function sendText(string $recipient, string $message, ?string $senderId = null, array $context = []): array
    {
        $this->assertConfigured();

        $payload = [
            'from' => $senderId ?: (string) config('services.sms.sender_id'),
            'to' => $this->normalizeRecipient($recipient),
            'text' => trim($message),
        ];

        try {
            $response = $this->request()->post((string) config('services.sms.endpoint'), $payload);
            $this->throwIfFailed($response);
        } catch (Throwable $exception) {
            Log::error('SMS delivery failed.', [
                'provider' => (string) config('services.sms.driver', 'nextsms'),
                'recipient' => $this->maskRecipient($payload['to']),
                'sender_id' => $payload['from'],
                'context' => $context,
                'error' => $exception->getMessage(),
            ]);

            throw new SmsDeliveryException('SMS delivery failed.', 0, $exception);
        }

        return [
            'status_code' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.sms.timeout', 15))
            ->withBasicAuth(
                (string) config('services.sms.api_key'),
                (string) config('services.sms.secret_key'),
            );
    }

    private function throwIfFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new SmsDeliveryException(
            sprintf('SMS provider rejected the request with status %d.', $response->status())
        );
    }

    private function assertConfigured(): void
    {
        if (!$this->isEnabled()) {
            throw new SmsDeliveryException('SMS delivery is disabled.');
        }

        foreach (['endpoint', 'api_key', 'secret_key', 'sender_id'] as $key) {
            if (blank(config("services.sms.{$key}"))) {
                throw new SmsDeliveryException(sprintf('SMS configuration is incomplete: %s is required.', $key));
            }
        }
    }

    private function normalizeRecipient(string $recipient): string
    {
        $recipient = trim($recipient);

        if ($recipient === '') {
            throw new SmsDeliveryException('SMS recipient is required.');
        }

        if (str_starts_with($recipient, '+')) {
            return '+'.preg_replace('/\D+/', '', substr($recipient, 1));
        }

        return preg_replace('/\D+/', '', $recipient);
    }

    private function maskRecipient(string $recipient): string
    {
        $visible = substr($recipient, -4);

        return str_repeat('*', max(strlen($recipient) - 4, 0)).$visible;
    }
}
