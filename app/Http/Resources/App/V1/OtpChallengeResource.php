<?php

namespace App\Http\Resources\App\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OtpChallengeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'challenge_id' => $this['challenge_id'],
        ];
    }
}
