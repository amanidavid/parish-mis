<?php

namespace App\Services\V1\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleIdentityService
{
    /**
     * Verify a Google ID token and normalize the identity payload.
     */
    public function verifyIdToken(string $credential): array
    {
        $clientId = (string) config('services.google.client_id');

        if ($clientId === '') {
            throw new RuntimeException('Google sign-in is not configured.');
        }

        try {
            $response = Http::timeout((int) config('services.google.timeout', 10))
                ->acceptJson()
                ->get((string) config('services.google.tokeninfo_endpoint'), [
                    'id_token' => $credential,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Google sign-in is temporarily unavailable. Please try again.');
        }

        if ($response->failed()) {
            throw new RuntimeException('The Google credential is invalid or expired.');
        }

        $payload = $response->json();
        $audience = (string) ($payload['aud'] ?? '');
        $subject = (string) ($payload['sub'] ?? '');
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($audience !== $clientId) {
            throw new RuntimeException('The Google credential was issued for another application.');
        }

        if ($subject === '' || $email === '') {
            throw new RuntimeException('The Google account payload is incomplete.');
        }

        if (!$emailVerified) {
            throw new RuntimeException('The selected Google email address is not verified.');
        }

        return [
            'subject' => $subject,
            'email' => $email,
            'email_verified' => true,
            'name' => trim((string) ($payload['name'] ?? '')),
            'picture' => trim((string) ($payload['picture'] ?? '')),
        ];
    }
}
