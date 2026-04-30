<?php

namespace App\Support;

final class PhoneNormalizer
{
    /**
     * Цифры E.164-подобно для РФ: 79XXXXXXXXX или null если пусто.
     */
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.substr($digits, 1);
        }
        if (strlen($digits) === 10 && ! str_starts_with($digits, '7')) {
            $digits = '7'.$digits;
        }

        return strlen($digits) >= 10 ? $digits : null;
    }
}
