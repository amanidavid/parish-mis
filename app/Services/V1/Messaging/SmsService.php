<?php

namespace App\Services\V1\Messaging;

use App\Services\V1\Messaging\Exceptions\SmsDeliveryException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    public function sendText(string|array $recipients, string $message, ?string $senderId = null, array $context = [], ?string $reference = null): array
    {
        $this->assertConfigured();

        $payload = [
            'from' => $senderId ?: (string) config('services.sms.sender_id'),
            'to' => $this->normalizeRecipients($recipients),
            'text' => trim($message),
            'reference' => $reference ?: $this->buildReference(),
        ];

        $response = null;

        try {
            $response = $this->request()->post((string) config('services.sms.endpoint'), $payload);
            $this->throwIfFailed($response);
        } catch (Throwable $exception) {
            Log::error('SMS delivery failed.', [
                'provider' => (string) config('services.sms.driver', 'nextsms'),
                'recipient' => $this->maskRecipients($payload['to']),
                'sender_id' => $payload['from'],
                'context' => $context,
                'error' => $exception->getMessage(),
                'provider_status' => $response?->status(),
                'provider_response' => $response ? $this->responseBodySummary($response) : null,
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
        $request = $this->http
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.sms.timeout', 15))
            ->withBasicAuth(
                (string) config('services.sms.api_key'),
                (string) config('services.sms.secret_key'),
            );

        $caBundle = trim((string) config('services.sms.ca_bundle', ''));
        if ($caBundle !== '') {
            return $request->withOptions(['verify' => $caBundle]);
        }

        if (!(bool) config('services.sms.verify_ssl', true)) {
            return $request->withoutVerifying();
        }

        return $request;
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

    private function normalizeRecipients(string|array $recipients): array
    {
        $recipients = is_array($recipients) ? $recipients : [$recipients];
        $normalized = [];

        foreach ($recipients as $recipient) {
            $recipient = trim((string) $recipient);

            if ($recipient === '') {
                continue;
            }

            if (str_starts_with($recipient, '+')) {
                $normalized[] = '+'.preg_replace('/\D+/', '', substr($recipient, 1));

                continue;
            }

            $normalized[] = preg_replace('/\D+/', '', $recipient);
        }

        if ($normalized === []) {
            throw new SmsDeliveryException('SMS recipient is required.');
        }

        return array_values(array_unique($normalized));
    }

    private function maskRecipients(array $recipients): array
    {
        return array_map(function (string $recipient) {
            $visible = substr($recipient, -4);

            return str_repeat('*', max(strlen($recipient) - 4, 0)).$visible;
        }, $recipients);
    }

    private function responseBodySummary(Response $response): array|string|null
    {
        $json = $response->json();

        if (is_array($json)) {
            return $json;
        }

        $body = trim($response->body());

        if ($body === '') {
            return null;
        }

        if (mb_strlen($body) > 500) {
            return mb_substr($body, 0, 500).'...';
        }

        return $body;
    }

    private function buildReference(): string
    {
        return 'sms-'.Str::lower(Str::random(12));
    }
}
