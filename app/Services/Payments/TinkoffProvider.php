<?php

namespace App\Services\Payments;

use App\Models\SaasApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TinkoffProvider implements PaymentProviderInterface
{
    public function __construct(
        private array $config = []
    ) {
    }

    public function normalizeAmount(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public function createCheckout(?SaasApiKey $apiKey, float $amount, array $context = []): array
    {
        if (empty($this->config['terminal_key']) || empty($this->config['password'])) {
            throw new \RuntimeException('Invalid provider config');
        }

        $paymentId = (int) ($context['payment_id'] ?? 0);
        $orderId = 'pay_' . $paymentId;

        $notificationUrl = $this->config['notification_url'] ?? rtrim(config('app.url', ''), '/') . '/api/v1/webhook/tinkoff';
        $returnToken = (string) ($context['return_token'] ?? '');
        $urls = $this->buildReturnUrls($paymentId, $returnToken);

        $payload = [
            'TerminalKey' => $this->config['terminal_key'] ?? '',
            'Amount' => (int) round($amount * 100),
            'OrderId' => $orderId,
            'Description' => $context['description'] ?? ($apiKey !== null ? 'API Key Top-up #' . $apiKey->id : 'Оплата заказа'),
            'NotificationURL' => $notificationUrl,
            'SuccessURL' => $urls['success'],
            'FailURL' => $urls['fail'],
        ];

        $payload['Token'] = $this->generateRequestToken($payload);

        $base = ($this->config['mode'] ?? 'prod') === 'test'
            ? 'https://rest-api-test.tinkoff.ru'
            : 'https://securepay.tinkoff.ru';

        $response = Http::asJson()
            ->timeout(10)
            ->post($base . '/v2/Init', $payload);

        $body = $response->json();
        if (!($body['Success'] ?? false)) {
            $message = $body['Message'] ?? $body['Details'] ?? 'Tinkoff Init failed';
            throw new \RuntimeException($message);
        }

        return [
            'provider_id' => (string) ($body['PaymentId'] ?? ''),
            'checkout_url' => $body['PaymentURL'] ?? null,
            'raw' => $body,
        ];
    }

    public function handleWebhook(Request $request): array
    {
        if (empty($this->config['terminal_key']) || empty($this->config['password'])) {
            return ['ok' => false, 'return_ok' => true, 'provider_id' => null, 'provider_event_id' => null, 'status' => null, 'amount_total' => null, 'currency' => null];
        }

        $data = $request->all();
        if (!is_array($data) || empty($data)) {
            $content = $request->getContent();
            $data = json_decode($content, true) ?? [];
        }

        $receivedToken = (string) ($data['Token'] ?? '');
        if ($receivedToken === '') {
            return ['ok' => false, 'return_ok' => true, 'provider_id' => null, 'provider_event_id' => null, 'status' => null, 'amount_total' => null, 'currency' => null];
        }

        $expectedToken = $this->generateRequestToken($data);
        Log::info('Tinkoff WEBHOOK TOKEN CHECK', [
            'incoming' => $receivedToken,
            'calculated' => $expectedToken,
        ]);
        if (!hash_equals($expectedToken, $receivedToken)) {
            Log::warning('Tinkoff WEBHOOK EXIT', ['reason' => 'token_fail']);
            return ['ok' => false, 'return_ok' => true, 'provider_id' => null, 'provider_event_id' => null, 'status' => null, 'amount_total' => null, 'currency' => null];
        }

        $rawStatus = strtoupper((string) ($data['Status'] ?? ''));
        $status = $this->mapTinkoffStatus($rawStatus);
        Log::info('Tinkoff WEBHOOK STATUS', [
            'raw' => $rawStatus,
            'mapped' => $status,
        ]);
        $paymentId = isset($data['PaymentId']) ? (string) $data['PaymentId'] : '';
        $eventId = $paymentId !== '' && $rawStatus !== '' ? $paymentId . '_' . $rawStatus : $paymentId;
        $orderId = $data['OrderId'] ?? '';
        $amountTotal = isset($data['Amount']) ? (int) $data['Amount'] : null;
        $currency = strtolower((string) ($data['Currency'] ?? 'rub'));

        return [
            'ok' => true,
            'provider_id' => $paymentId,
            'provider_event_id' => $eventId,
            'status' => $status,
            'amount_total' => $amountTotal,
            'currency' => $currency,
            'order_id' => $orderId,
        ];
    }

    /**
     * Подпись для Init и уведомлений T-Bank: все корневые скалярные поля, кроме Token
     * и вложенных объектов (Receipt, DATA, Shops).
     *
     * @see https://www.tbank.ru/kassa/develop/api/notifications/https/
     */
    private function generateRequestToken(array $data): string
    {
        $exclude = ['Token', 'Receipt', 'DATA', 'Shops'];
        $tokenData = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $exclude, true)) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $tokenData[$key] = is_bool($value) ? (($value ? 'true' : 'false')) : (string) $value;
        }
        $tokenData['Password'] = (string) ($this->config['password'] ?? '');
        ksort($tokenData);

        return hash('sha256', implode('', $tokenData));
    }

    private function buildReturnUrls(int $paymentId, string $returnToken = ''): array
    {
        $base = config('app.frontend_url');
        if (!$base) {
            throw new \RuntimeException('FRONTEND_URL is required in .env');
        }
        $base = rtrim($base, '/');
        $tokenQuery = $returnToken !== '' ? '&return_token=' . urlencode($returnToken) : '';
        return [
            'success' => $base . '/payment/success?payment_id=' . $paymentId . $tokenQuery,
            'fail' => $base . '/payment/fail?payment_id=' . $paymentId . $tokenQuery,
        ];
    }

    private function mapTinkoffStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'CONFIRMED' => 'succeeded',
            'AUTHORIZED' => 'succeeded',
            'REJECTED', 'CANCELED', 'DEADLINE_EXPIRED' => 'failed',
            default => '',
        };
    }
}
