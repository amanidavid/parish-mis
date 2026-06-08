<?php

namespace App\Services\V1;

use App\Models\Landlord\BaseUser;
use App\Models\Landlord\OtpToken;
use App\Services\V1\Messaging\SmsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class OtpService
{
    public function __construct(
        private SmsService $smsService,
    ) {
    }

    public function create(int $userId, string $purpose = 'login', ?string $channel = null): array
    {
        $length = (int) config('otp.length', 6);
        $ttl = (int) config('otp.ttl', 300);
        $maxAttempts = (int) config('otp.max_attempts', 5);
        $channel = $this->resolveChannel($channel);

        $code = $this->generateCode($length);
        $hash = $this->hashCode($code);

        $token = DB::connection('base')->transaction(function () use ($userId, $purpose, $hash, $channel, $ttl, $maxAttempts) {
            $token = new OtpToken();
            $token->user_id = $userId;
            $token->purpose = $purpose;
            $token->code_hash = $hash;
            $token->channel = $channel;
            $token->expires_at = now()->addSeconds($ttl);
            $token->attempts = 0;
            $token->max_attempts = $maxAttempts;
            $token->save();

            return $token;
        });

        try {
            $this->deliverCode($token, $code, $ttl);
        } catch (Throwable $exception) {
            OtpToken::query()->whereKey($token->id)->delete();

            throw $exception;
        }

        $result = ['challenge_id' => $token->uuid];

        if ((bool) config('app.debug', false)) {
            $result['code_dev'] = $code;
        }

        return $result;
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

    private function resolveChannel(?string $channel): string
    {
        $channel = trim((string) $channel);

        if ($channel !== '') {
            return $channel;
        }

        $configured = trim((string) config('otp.delivery_channel', 'log'));

        if ($configured === 'sms' && !$this->smsService->isEnabled()) {
            if ((bool) config('otp.log_fallback', true)) {
                return 'log';
            }

            throw new RuntimeException('OTP SMS delivery is enabled in configuration, but SMS transport is not configured.');
        }

        return $configured !== '' ? $configured : 'log';
    }

    private function deliverCode(OtpToken $token, string $code, int $ttl): void
    {
        if ($token->channel === 'log') {
            Log::info('[OTP] purpose='.$token->purpose.' user_id='.$token->user_id.' code='.$code.' challenge_id='.$token->uuid);

            return;
        }

        if ($token->channel !== 'sms') {
            throw new RuntimeException(sprintf('Unsupported OTP delivery channel [%s].', $token->channel));
        }

        $user = BaseUser::query()->find($token->user_id);

        if (!$user || blank($user->phone)) {
            throw new RuntimeException('OTP could not be sent because the user has no phone number.');
        }

        $message = strtr((string) config('otp.sms_template'), [
            ':code' => $code,
            ':minutes' => (string) max((int) ceil($ttl / 60), 1),
            ':purpose' => (string) $token->purpose,
        ]);

        $this->smsService->sendText((string) $user->phone, $message, null, [
            'purpose' => $token->purpose,
            'user_id' => $token->user_id,
            'challenge_id' => $token->uuid,
        ]);
    }
}
