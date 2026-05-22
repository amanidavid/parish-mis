<?php

namespace App\Services\V1;

use Illuminate\Support\Str;

class JwtService
{
    private function now(): int
    {
        return time();
    }

    private function jwtSecret(): string
    {
        $secret = (string) (config('app.key') ?? '');
        $envSecret = env('JWT_SECRET');
        if (!empty($envSecret)) {
            $secret = $envSecret;
        }
        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7));
            return $decoded === false ? '' : $decoded;
        }
        return $secret;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padLen = 4 - $remainder;
            $data .= str_repeat('=', $padLen);
        }
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }

    private function sign(string $headerPayload, string $secret): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $headerPayload, $secret, true));
    }

    public function issueTokenForSubject(string $subjectUuid, int $ttlSeconds = 900): array
    {
        $now = $this->now();
        $payload = [
            'iss' => url('/'),
            'sub' => $subjectUuid,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'jti' => (string) Str::uuid(),
        ];
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $secret = $this->jwtSecret();
        $headerB64 = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadB64 = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = $this->sign($headerB64.'.'.$payloadB64, $secret);
        $jwt = $headerB64.'.'.$payloadB64.'.'.$signature;
        return [$jwt, $payload['exp'] - $now];
    }

    public function issueTokenForModel(object $user, int $ttlSeconds = 900): array
    {
        $now = $this->now();
        $payload = [
            'iss' => url('/'),
            'sub' => $user->uuid,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'jti' => (string) Str::uuid(),
        ];
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $secret = $this->jwtSecret();
        $headerB64 = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadB64 = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = $this->sign($headerB64.'.'.$payloadB64, $secret);
        $jwt = $headerB64.'.'.$payloadB64.'.'.$signature;
        return [$jwt, $payload['exp'] - $now];
    }

    public function decodeToken(string $jwt, bool $ignoreExpiration = false): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \Exception('Invalid token');
        }
        [$headerB64, $payloadB64, $signature] = $parts;
        $header = json_decode($this->base64UrlDecode($headerB64), true) ?: [];
        $payload = json_decode($this->base64UrlDecode($payloadB64), true) ?: [];
        if (($header['alg'] ?? '') !== 'HS256') {
            throw new \Exception('Unsupported algorithm');
        }
        $expected = $this->sign($headerB64.'.'.$payloadB64, $this->jwtSecret());
        if (!hash_equals($expected, $signature)) {
            throw new \Exception('Invalid signature');
        }
        if (!$ignoreExpiration) {
            $exp = $payload['exp'] ?? 0;
            if ($exp < $this->now()) {
                throw new \Exception('Token expired');
            }
        }
        return $payload;
    }
}
