<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\SaasApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SberProvider implements PaymentProviderInterface
{
    private const BASE_PROD = 'https://securepayments.sberbank.ru';
    private const BASE_TEST = 'https://3dsec.sberbank.ru';

    public function __construct(
        private array $config = []
    ) {
    }

    public function normalizeAmount(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public function createCheckout(SaasApiKey $apiKey, float $amount, array $context = []): array
    {
        if (empty($this->config['merchant_login']) || empty($this->config['password'])) {
            throw new \RuntimeException('Invalid provider config: merchant_login and password required');
        }

        $paymentId = (int) ($context['payment_id'] ?? 0);
        $orderNumber = 'pay_' . $paymentId;
        $amountKopecks = (int) round($amount * 100);
        $returnToken = (string) ($context['return_token'] ?? '');

        $urls = $this->buildReturnUrls($paymentId, $returnToken);

        $formData = [
            'userName' => $this->config['merchant_login'],
            'password' => $this->config['password'],
            'orderNumber' => $orderNumber,
            'amount' => $amountKopecks,
            'returnUrl' => $urls['success'],
            'failUrl' => $urls['fail'],
            'description' => $context['description'] ?? 'Payment #' . $paymentId,
        ];

        $base = ($this->config['mode'] ?? 'prod') === 'test' ? self::BASE_TEST : self::BASE_PROD;
        $response = Http::asForm()
            ->timeout(15)
            ->post($base . '/payment/rest/register.do', $formData);

        $body = $response->json() ?? [];
        $errorCode = $body['errorCode'] ?? null;
        $errorMessage = $body['errorMessage'] ?? '';

        if ($errorCode !== null && $errorCode !== '' && (int) $errorCode !== 0) {
            throw new \RuntimeException($errorMessage ?: 'Sber register failed');
        }

        $orderId = (string) ($body['orderId'] ?? '');
        $formUrl = $body['formUrl'] ?? null;

        if ($orderId === '' || $formUrl === null) {
            throw new \RuntimeException($errorMessage ?: 'Sber register: missing orderId or formUrl');
        }

        return [
            'provider_id' => $orderId,
            'checkout_url' => $formUrl,
            'raw' => $body,
        ];
    }

    public function handleWebhook(Request $request): array
    {
        if (empty($this->config['merchant_login']) || empty($this->config['password'])) {
            return ['ok' => false, 'return_ok' => true, 'provider_id' => null, 'provider_event_id' => null, 'status' => null, 'amount_total' => null, 'currency' => null];
        }

        $orderNumber = (string) ($request->input('orderNumber') ?? $request->input('mdOrder') ?? '');
        if ($orderNumber === '' || !preg_match('/^pay_(\d+)$/', $orderNumber, $m)) {
            return ['ok' => false, 'return_ok' => true, 'provider_id' => null, 'provider_event_id' => null, 'status' => null, 'amount_total' => null, 'currency' => null];
        }

        $paymentId = (int) $m[1];
        $payment = Payment::where('provider', 'sber')->where('id', $paymentId)->first();
        if (!$payment || !$payment->provider_id) {
            return ['ok' => false, 'return_ok' => true, 'provider_id' => null, 'provider_event_id' => null, 'status' => null, 'amount_total' => null, 'currency' => null];
        }

        if ($payment->status === 'succeeded') {
            return ['ok' => false, 'return_ok' => true, 'provider_id' => null, 'provider_event_id' => null, 'status' => null, 'amount_total' => null, 'currency' => null];
        }

        $formData = [
            'userName' => $this->config['merchant_login'],
            'password' => $this->config['password'],
            'orderId' => $payment->provider_id,
        ];

        $base = ($this->config['mode'] ?? 'prod') === 'test' ? self::BASE_TEST : self::BASE_PROD;
        $response = Http::asForm()->timeout(10)->post($base . '/payment/rest/getOrderStatus.do', $formData);
        $body = $response->json() ?? [];
        Log::info('SBER STATUS', $body);

        $errorCode = $body['errorCode'] ?? null;
        if ($errorCode !== null && $errorCode !== '' && (int) $errorCode !== 0) {
            return ['ok' => false, 'return_ok' => true, 'provider_id' => null, 'provider_event_id' => null, 'status' => null, 'amount_total' => null, 'currency' => null];
        }

        $orderStatus = (int) ($body['orderStatus'] ?? -1);
        $providerEventId = $payment->provider_id . '_' . $orderStatus;
        $mappedStatus = $this->mapOrderStatus($orderStatus);

        $incoming = (int) ($body['amount'] ?? $body['orderAmount'] ?? $body['orderSum'] ?? -1);
        $expected = (int) round((float) $payment->amount * 100);

        if ($orderStatus === 2) {
            if ($incoming !== $expected) {
                throw new \RuntimeException('Amount mismatch');
            }
        } else {
            $incoming = $incoming > 0 ? $incoming : $expected;
        }

        return [
            'ok' => true,
            'provider_id' => $payment->provider_id,
            'provider_event_id' => $providerEventId,
            'status' => $mappedStatus,
            'amount_total' => $incoming,
            'currency' => strtolower((string) ($body['currency'] ?? 'rub')),
            'order_id' => $orderNumber,
        ];
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

    private function mapOrderStatus(int $orderStatus): string
    {
        return match ($orderStatus) {
            2 => 'succeeded',
            3, 4 => 'failed',
            default => '',
        };
    }
}
