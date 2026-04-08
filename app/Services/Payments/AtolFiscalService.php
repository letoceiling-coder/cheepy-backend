<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AtolFiscalService
{
    private const BASE_URL = 'https://online.atol.ru/possystem/v4';
    private const TOKEN_CACHE_KEY = 'atol_token_';
    private const TOKEN_TTL_SEC = 3600;

    public function __construct(
        private array $config
    ) {
    }

    public static function fromProvider(string $name = 'atol'): ?self
    {
        $record = PaymentProvider::where('name', $name)->first();
        if (!$record || empty($record->config['login']) || empty($record->config['password']) || empty($record->config['group_code'])) {
            return null;
        }
        return new self($record->config);
    }

    /**
     * Create fiscal receipt for a succeeded payment.
     *
     * @return string|null Operation UUID or null on failure
     */
    public function createReceipt(Payment $payment, ?string $clientEmail = null): ?string
    {
        $token = $this->getToken();
        if ($token === null) {
            return null;
        }

        $groupCode = $this->config['group_code'];
        $amountKopecks = (int) round((float) $payment->amount * 100);
        $tax = in_array($this->config['tax'] ?? '', ['vat0', 'vat10', 'vat20', 'none'], true)
            ? $this->config['tax']
            : 'none';

        $email = $clientEmail ?? $payment->user_email ?? $this->config['email'] ?? 'client@example.com';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = $this->config['email'] ?? 'client@example.com';
        }

        $paymentMethod = $this->config['payment_method'] ?? 'full_payment';
        $paymentObject = $this->config['payment_object'] ?? 'service';

        $item = [
            'name' => 'Payment',
            'price' => $amountKopecks,
            'quantity' => 1,
            'sum' => $amountKopecks,
            'tax' => $tax,
        ];
        if (!empty($paymentMethod)) {
            $item['payment_method'] = $paymentMethod;
        }
        if (!empty($paymentObject)) {
            $item['payment_object'] = $paymentObject;
        }

        $payload = [
            'timestamp' => now()->format('d.m.Y H:i:s'),
            'external_id' => 'payment_' . $payment->id,
            'receipt' => [
                'client' => [
                    'email' => $email,
                ],
                'items' => [$item],
                'total' => $amountKopecks,
            ],
        ];

        Log::info('ATOL REQUEST', ['payment_id' => $payment->id, 'url' => self::BASE_URL . '/' . $groupCode . '/sell', 'payload' => $payload]);

        $response = Http::withHeaders([
            'Token' => $token,
            'Content-Type' => 'application/json',
        ])->timeout(15)->post(self::BASE_URL . '/' . $groupCode . '/sell', $payload);

        $body = $response->json() ?? [];
        Log::info('ATOL RESPONSE', ['payment_id' => $payment->id, 'body' => $body]);

        $uuid = $body['uuid'] ?? null;
        if ($uuid !== null) {
            return (string) $uuid;
        }

        $error = $body['error'] ?? $body['text'] ?? 'ATOL sell failed';
        Log::warning('ATOL SELL failed', ['payment_id' => $payment->id, 'error' => $error]);

        return null;
    }

    public function getReport(string $uuid): ?array
    {
        $token = $this->getToken();
        if ($token === null) {
            return null;
        }

        $groupCode = $this->config['group_code'];
        $response = Http::withHeaders(['Token' => $token])
            ->timeout(10)
            ->get(self::BASE_URL . '/' . $groupCode . '/report/' . $uuid);

        return $response->json();
    }

    private function getToken(): ?string
    {
        $cacheKey = self::TOKEN_CACHE_KEY . md5(($this->config['login'] ?? '') . ($this->config['group_code'] ?? ''));
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::info('ATOL REQUEST', ['endpoint' => 'getToken']);
        $response = Http::asJson()->timeout(10)->post(self::BASE_URL . '/getToken', [
            'login' => $this->config['login'],
            'pass' => $this->config['password'],
        ]);

        $body = $response->json() ?? [];
        Log::info('ATOL RESPONSE', ['endpoint' => 'getToken', 'body' => $body]);

        $token = $body['token'] ?? null;
        if ($token === null) {
            Log::warning('ATOL getToken failed', ['response' => $body]);
            return null;
        }

        $ttl = (int) ($body['timestamp'] ?? self::TOKEN_TTL_SEC);
        Cache::put($cacheKey, $token, $ttl > 0 ? $ttl : self::TOKEN_TTL_SEC);

        return $token;
    }
}
