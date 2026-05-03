<?php

namespace App\Services\Storefront;

use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Support\Str;

class ReferralService
{
    public function codeFor(User $user): ReferralCode
    {
        $existing = ReferralCode::query()->where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }

        do {
            $code = 'CHEEPY-'.Str::upper(Str::random(6));
        } while (ReferralCode::query()->where('code', $code)->exists());

        return ReferralCode::query()->create([
            'user_id' => $user->id,
            'code' => $code,
            'is_active' => true,
        ]);
    }

    public function linkFor(User $user): string
    {
        $code = $this->codeFor($user)->code;
        $base = rtrim((string) config('app.frontend_url', 'https://siteaacess.store'), '/');

        return $base.'/?ref='.rawurlencode($code);
    }
}
