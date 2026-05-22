<?php

namespace App\Services\V1;

use App\Models\Landlord\OtpToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class OtpService
{
    public function create(int $userId, string $purpose = 'login', string $channel = 'log'): array
    {
        $length = (int) env('OTP_LENGTH', 6);
        $ttl = (int) env('OTP_TTL', 300);
        $maxAttempts = (int) env('OTP_MAX_ATTEMPTS', 5);

        $code = $this->generateCode($length);
        $hash = $this->hashCode($code);

        $token = new OtpToken();
        $token->user_id = $userId;
        $token->purpose = $purpose;
        $token->code_hash = $hash;
        $token->channel = $channel;
        $token->expires_at = now()->addSeconds($ttl);
        $token->attempts = 0;
        $token->max_attempts = $maxAttempts;
        $token->save();

        if ($channel === 'log') {
            Log::info('[OTP] purpose='.$purpose.' user_id='.$userId.' code='.$code.' challenge_id='.$token->uuid);
        }

        return ['challenge_id' => $token->uuid, 'code_dev' => $code];
    }

    public function verify(string $challengeUuid, string $code, string $purpose = 'login'): bool
    {
        return DB::connection('base')->transaction(function () use ($challengeUuid, $code, $purpose) {
            /** @var OtpToken|null $otp */
            $otp = OtpToken::query()->where('uuid', $challengeUuid)->first();
            if (!$otp) {
                return false;
            }
            if ($otp->purpose !== $purpose) {
                return false;
            }
            if ($otp->consumed_at !== null) {
                return false;
            }
            if (now()->greaterThan($otp->expires_at)) {
                return false;
            }
            if ($otp->attempts >= $otp->max_attempts) {
                return false;
            }
            $otp->attempts++;
            $ok = hash_equals($otp->code_hash, $this->hashCode($code));
            if ($ok) {
                $otp->consumed_at = now();
            }
            $otp->save();
            return $ok;
        });
    }

    private function generateCode(int $length = 6): string
    {
        $min = (int) pow(10, $length - 1);
        $max = (int) pow(10, $length) - 1;
        return (string) random_int($min, $max);
    }

    private function hashCode(string $code): string
    {
        $secret = (string) (config('app.key') ?? 'secret');
        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7));
            $secret = $decoded === false ? 'secret' : $decoded;
        }
        return hash('sha256', $code.'|'.$secret);
    }
}
