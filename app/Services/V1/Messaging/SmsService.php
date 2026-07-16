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
    /**
     * Create a new instance.
     */
    public function __construct(
        private HttpFactory $http,
    ) {
    }

    /**
     * Determine whether enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) config('services.sms.enabled', false);
    }

    /**
     * Handle send text.
     */
    public function sendText(string|array $recipients, string $message, ?string $senderId = null, array $context = [], ?string $reference = null): array
    {
        $this->assertConfigured();
        $normalizedRecipients = $this->normalizeRecipients($recipients);
        $this->assertSupportedRecipients($normalizedRecipients);

        $payload = [
            'from' => $senderId ?: (string) config('services.sms.sender_id'),
            'to' => $normalizedRecipients,
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

    /**
     * Determine whether a recipient is supported by the configured SMS provider.
     */
    public function supportsRecipient(?string $recipient): bool
    {
        $recipient = trim((string) $recipient);
        if ($recipient === '') {
            return false;
        }

        try {
            $normalized = $this->normalizeRecipient($recipient);
        } catch (SmsDeliveryException) {
            return false;
        }

        return $this->isSupportedNormalizedRecipient($normalized);
    }

    /**
     * Message for unsupported SMS destination.
     */
    public function unsupportedRecipientMessage(): string
    {
        return 'SMS delivery is available only for Tanzania phone numbers right now.';
    }

    /**
     * Request.
     */
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

    /**
     * Throw if failed.
     */
    private function throwIfFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new SmsDeliveryException(
            sprintf('SMS provider rejected the request with status %d.', $response->status())
        );
    }

    /**
     * Assert configured.
     */
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

    /**
     * Normalize recipients.
     */
    private function normalizeRecipients(string|array $recipients): array
    {
        $recipients = is_array($recipients) ? $recipients : [$recipients];
        $normalized = [];

        foreach ($recipients as $recipient) {
            $recipient = trim((string) $recipient);

            if ($recipient === '') {
                continue;
            }

            $normalized[] = $this->normalizeRecipient($recipient);
        }

        if ($normalized === []) {
            throw new SmsDeliveryException('SMS recipient is required.');
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Assert that recipients are supported by the configured SMS provider.
     */
    private function assertSupportedRecipients(array $recipients): void
    {
        foreach ($recipients as $recipient) {
            if (!$this->isSupportedNormalizedRecipient($recipient)) {
                throw new SmsDeliveryException($this->unsupportedRecipientMessage());
            }
        }
    }

    /**
     * Normalize a single recipient for SMS delivery.
     */
    private function normalizeRecipient(string $recipient): string
    {
        $hasPlusPrefix = str_starts_with($recipient, '+');
        $digits = preg_replace('/\D+/', '', $recipient);

        if ($digits === '') {
            throw new SmsDeliveryException('SMS recipient is required.');
        }

        if ($hasPlusPrefix) {
            return '+'.$digits;
        }

        // Convert Tanzania-local formats into the provider-required +255 format.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '255')) {
            return '+'.$digits;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return '+255'.substr($digits, 1);
        }

        if (strlen($digits) === 9 && preg_match('/^[67]\d{8}$/', $digits) === 1) {
            return '+255'.$digits;
        }

        return '+'.$digits;
    }

    /**
     * Determine whether the normalized recipient is allowed for the current provider.
     */
    private function isSupportedNormalizedRecipient(string $recipient): bool
    {
        $supportedPrefixes = config('services.sms.supported_prefixes', ['+255']);
        $supportedPrefixes = is_array($supportedPrefixes) ? $supportedPrefixes : ['+255'];

        foreach ($supportedPrefixes as $prefix) {
            $prefix = trim((string) $prefix);

            if ($prefix !== '' && str_starts_with($recipient, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask recipients.
     */
    private function maskRecipients(array $recipients): array
    {
        return array_map(function (string $recipient) {
            $visible = substr($recipient, -4);

            return str_repeat('*', max(strlen($recipient) - 4, 0)).$visible;
        }, $recipients);
    }

    /**
     * Response body summary.
     */
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

    /**
     * Build reference.
     */
    private function buildReference(): string
    {
        return 'sms-'.Str::lower(Str::random(12));
    }
}
