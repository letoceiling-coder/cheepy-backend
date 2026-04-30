<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * REST SMS по документации iqsms / SMS Дисконт: {@see https://iqsms.ru/api/api_rest/}
 */
final class IqsmsSmsGateway
{
    public const DEFAULT_BASE = 'https://api.iqsms.ru';

    /**
     * Нормализация для РФ: итог +7XXXXXXXXXX (11 цифр после +7).
     */
    public static function normalizePhoneRu(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === null || $digits === '') {
            return null;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '7'.$digits;
        }
        if (! str_starts_with($digits, '7') || strlen($digits) !== 11) {
            return null;
        }

        return '+'.$digits;
    }

    /**
     * @return array{success: bool, message: string, balances?: list<string>, raw?: string}
     */
    public function balance(string $login, string $password, ?string $baseUrl = null): array
    {
        $base = rtrim($baseUrl ?: self::DEFAULT_BASE, '/');

        try {
            $response = Http::timeout(20)
                ->withBasicAuth($login, $password)
                ->accept('text/plain')
                ->get($base.'/messages/v2/balance/');
        } catch (\Throwable $e) {
            Log::warning('iqsms balance transport', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Ошибка сети: '.$e->getMessage()];
        }

        $body = trim((string) $response->body());

        if (! $response->successful()) {
            return ['success' => false, 'message' => 'HTTP '.$response->status().': '.$body];
        }

        $lines = preg_split('/\R/', $body) ?: [];
        $balances = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_contains($line, ';')) {
                $balances[] = $line;
            }
        }

        if ($balances === []) {
            return ['success' => false, 'message' => 'Неожиданный ответ баланса', 'raw' => $body];
        }

        return [
            'success' => true,
            'message' => implode('; ', $balances),
            'balances' => $balances,
            'raw' => $body,
        ];
    }

    /**
     * @return array{success: bool, message: string, message_id?: string, raw?: string}
     */
    public function send(
        string $login,
        string $password,
        string $phoneE164,
        string $text,
        ?string $sender,
        ?string $baseUrl = null
    ): array {
        $base = rtrim($baseUrl ?: self::DEFAULT_BASE, '/');
        $query = [
            'phone' => $phoneE164,
            'text' => $text,
        ];
        $sender = trim((string) $sender);
        if ($sender !== '') {
            $query['sender'] = $sender;
        }

        try {
            $response = Http::timeout(45)
                ->withBasicAuth($login, $password)
                ->accept('text/plain')
                ->get($base.'/messages/v2/send/', $query);
        } catch (\Throwable $e) {
            Log::warning('iqsms send transport', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Ошибка сети: '.$e->getMessage()];
        }

        $body = trim((string) $response->body());

        if (! $response->successful()) {
            return ['success' => false, 'message' => 'HTTP '.$response->status().': '.$body, 'raw' => $body];
        }

        return $this->parseSendBody($body);
    }

    /**
     * @return array{success: bool, message: string, message_id?: string, raw?: string}
     */
    private function parseSendBody(string $body): array
    {
        $parts = explode(';', $body, 2);
        $status = strtolower(trim($parts[0]));

        if ($status === 'accepted' && isset($parts[1])) {
            return [
                'success' => true,
                'message' => 'Сообщение принято',
                'message_id' => trim($parts[1]),
                'raw' => $body,
            ];
        }

        return [
            'success' => false,
            'message' => $body !== '' ? $body : 'Неизвестный ответ сервиса',
            'raw' => $body,
        ];
    }
}
