<?php

namespace App\Services\Storefront;

use App\Models\ReferralCode;
use App\Models\ReferralEvent;
use App\Models\ReferralLinkClick;
use App\Models\User;

/**
 * Клики по ?ref=CODE, привязка invited к referral_codes / referral_events.
 */
final class StorefrontReferralAttributionService
{
    public function normalizeCode(?string $raw): ?string
    {
        $c = strtoupper(trim((string) $raw));

        return preg_match('/^CHEEPY-[A-Z0-9]{4,16}$/', $c) ? $c : null;
    }

    public function findActiveByCode(?string $raw): ?ReferralCode
    {
        $norm = $this->normalizeCode($raw);
        if ($norm === null) {
            return null;
        }

        /** @var ReferralCode|null $row */
        $row = ReferralCode::query()
            ->where('is_active', true)
            ->whereRaw('UPPER(TRIM(code)) = ?', [$norm])
            ->first();

        return $row;
    }

    public function recordClick(?string $codeRaw, ?string $visitorHash, ?string $userAgent): bool
    {
        $code = $this->findActiveByCode($codeRaw);
        if (! $code) {
            return false;
        }

        ReferralLinkClick::query()->create([
            'referral_code_id' => $code->id,
            'visitor_hash' => $visitorHash ? substr((string) $visitorHash, 0, 128) : null,
            'ip_hash' => request()->ip() ? hash('sha256', (string) request()->ip().config('app.key')) : null,
            'user_agent' => $userAgent ? substr((string) $userAgent, 0, 500) : null,
            'clicked_at' => now(),
        ]);

        return true;
    }

    /** Опционально при регистрации (код в теле запроса): неверный код игнорируем. */
    public function tryRegisterAttribution(User $newUser, ?string $codeRaw): void
    {
        if ($this->hasRegistrationAttribution($newUser->id)) {
            return;
        }
        $code = $this->findActiveByCode($codeRaw);
        if ($code === null) {
            return;
        }
        if ((int) $code->user_id === (int) $newUser->id) {
            return;
        }

        ReferralEvent::query()->create([
            'referrer_user_id' => $code->user_id,
            'referred_user_id' => $newUser->id,
            'referral_code_id' => $code->id,
            'event_type' => 'registration',
            'reward_amount' => 0,
            'reward_granted_at' => null,
        ]);
    }

    /**
     * Привязка уже созданного аккаунта (например, после OAuth): только «свежий» профиль без события registration.
     *
     * @return ?string текст ошибки или null при успехе
     */
    public function tryAttachForNewishAccount(User $user, ?string $codeRaw): ?string
    {
        if ($this->hasRegistrationAttribution($user->id)) {
            return null;
        }

        $created = $user->created_at ?? now();
        if ($created->lt(now()->subHours(72))) {
            return 'Срок привязки реферального кода истёк — укажите его при регистрации по email.';
        }

        if (\App\Models\CustomerOrder::query()->where('user_id', $user->id)->where('payment_status', 'paid')->exists()) {
            return 'Нельзя привязать код: уже есть оплаченные заказы.';
        }

        $code = $this->findActiveByCode($codeRaw);
        if ($code === null) {
            return 'Реферальный код не найден или неактивен';
        }
        if ((int) $code->user_id === (int) $user->id) {
            return 'Нельзя использовать собственную ссылку';
        }

        ReferralEvent::query()->create([
            'referrer_user_id' => $code->user_id,
            'referred_user_id' => $user->id,
            'referral_code_id' => $code->id,
            'event_type' => 'registration',
            'reward_amount' => 0,
            'reward_granted_at' => null,
        ]);

        return null;
    }

    private function hasRegistrationAttribution(int $userId): bool
    {
        return ReferralEvent::query()
            ->where('referred_user_id', $userId)
            ->where('event_type', 'registration')
            ->exists();
    }
}
