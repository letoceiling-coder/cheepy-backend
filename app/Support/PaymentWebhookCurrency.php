<?php

namespace App\Support;

/**
 * В уведомлениях T‑Банк / СБЕР часто приходит ISO 4217 числом (643 = RUB), в CRM — строка rub.
 */
final class PaymentWebhookCurrency
{
    /**
     * Приводит код валюты из вебхука к lowercase alpha (rub, usd, eur).
     */
    public static function normalize(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }
        $s = strtolower(trim((string) $raw));
        if ($s === '') {
            return '';
        }

        return match ($s) {
            '643', '810', 'rub', 'rur' => 'rub',
            '840', 'usd' => 'usd',
            '978', 'eur' => 'eur',
            default => $s,
        };
    }
}
