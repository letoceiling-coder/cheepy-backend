<?php

namespace App\Services\Storefront;

use App\Models\DeliveryIntegration;
use Illuminate\Support\Facades\Http;

/**
 * Тариф через API Почты России «Отправка» (otpravka-api.pochta.ru).
 *
 * @see https://otpravka.pochta.ru/specification
 */
class RussianPostTariffService
{
    private const BASE = 'https://otpravka-api.pochta.ru';

    /**
     * @return array{ok: bool, message?: string, quote?: array<string, mixed>}
     */
    public function quote(
        string $indexFrom,
        string $indexTo,
        int $massG,
        ?int $declaredValueKopeks = null,
    ): array {
        $indexFrom = preg_replace('/\D/', '', $indexFrom);
        $indexTo = preg_replace('/\D/', '', $indexTo);
        if (strlen($indexFrom) !== 6 || strlen($indexTo) !== 6) {
            return ['ok' => false, 'message' => 'Нужны почтовые индексы отправителя и получателя (6 цифр)'];
        }

        $integration = DeliveryIntegration::query()->where('name', 'russian_post')->first();
        $config = $integration?->config ?? [];
        if (! $integration?->is_active) {
            return ['ok' => false, 'message' => 'Почта России не подключена'];
        }

        $token = trim((string) ($config['access_token'] ?? ''));
        if ($token === '') {
            return ['ok' => false, 'message' => 'Укажите токен API в интеграции Почты России'];
        }

        $senderIndex = trim((string) ($config['sender_postal_index'] ?? ''));
        $senderIndex = preg_replace('/\D/', '', $senderIndex);
        if (strlen($senderIndex) === 6) {
            $indexFrom = $senderIndex;
        }

        $mailType = trim((string) ($config['mail_type'] ?? 'POSTAL_PARCEL'));
        if ($mailType === '') {
            $mailType = 'POSTAL_PARCEL';
        }
        $mailCategory = trim((string) ($config['mail_category'] ?? 'ORDINARY'));
        $paymentMethod = trim((string) ($config['payment_method'] ?? 'CASHLESS'));

        $payload = [
            'index-from' => (int) $indexFrom,
            'index-to' => (int) $indexTo,
            'mass' => max(1, $massG),
            'mail-category' => $mailCategory,
            'mail-type' => $mailType,
            'payment-method' => $paymentMethod,
            'with-order-of-notice' => false,
            'with-simple-notice' => false,
        ];
        if ($declaredValueKopeks !== null && $declaredValueKopeks > 0) {
            $payload['insr-value'] = $declaredValueKopeks;
        }

        $headers = [
            'Authorization' => 'AccessToken '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        $login = trim((string) ($config['auth_login'] ?? ''));
        $password = (string) ($config['auth_password'] ?? '');
        if ($login !== '' && $password !== '') {
            $headers['X-User-Authorization'] = 'Basic '.base64_encode($login.':'.$password);
        }

        try {
            $res = Http::withHeaders($headers)
                ->timeout(25)
                ->post(self::BASE.'/1.0/tariff', $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Почта России: '.$e->getMessage()];
        }

        if (! $res->successful()) {
            $msg = 'Почта России HTTP '.$res->status();
            $j = $res->json();
            if (is_array($j)) {
                $errs = $j['errors'] ?? $j['messages'] ?? null;
                if (is_array($errs) && isset($errs[0]['message'])) {
                    $msg .= ': '.(string) $errs[0]['message'];
                } elseif (isset($j['message'])) {
                    $msg .= ': '.(string) $j['message'];
                }
            }

            return ['ok' => false, 'message' => $msg];
        }

        $j = $res->json();
        if (! is_array($j)) {
            return ['ok' => false, 'message' => 'Почта России: пустой ответ'];
        }

        $totalRate = (int) ($j['total-rate'] ?? $j['total_rate'] ?? 0);
        if ($totalRate <= 0) {
            return ['ok' => false, 'message' => 'Почта России: не удалось рассчитать тариф'];
        }

        $delivery = is_array($j['delivery-time'] ?? null) ? $j['delivery-time'] : [];
        $pMin = (int) ($delivery['min-days'] ?? $delivery['min_days'] ?? 1);
        $pMax = (int) ($delivery['max-days'] ?? $delivery['max_days'] ?? $pMin);

        return [
            'ok' => true,
            'quote' => [
                'integration' => 'russian_post',
                'provider_title' => 'Почта России',
                'service_code' => $mailType,
                'service_name' => 'Посылка онлайн',
                'delivery_mode' => 'domestic_parcel',
                'price_rub' => round($totalRate / 100, 2),
                'period_min_days' => $pMin,
                'period_max_days' => max($pMin, $pMax),
            ],
        ];
    }
}
