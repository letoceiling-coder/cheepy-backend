<?php

namespace App\Support;

use App\Models\SmsIntegration;

final class StorefrontSmsGate
{
    public static function phoneAuthEnabled(): bool
    {
        $row = SmsIntegration::query()->where('name', 'iqsms')->first();
        if (! $row || ! $row->is_active) {
            return false;
        }
        $c = $row->config ?? [];

        return trim((string) ($c['login'] ?? '')) !== ''
            && trim((string) ($c['password'] ?? '')) !== '';
    }
}
