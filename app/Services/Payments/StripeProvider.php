<?php

namespace App\Services\Payments;

use App\Models\SaasApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StripeProvider implements PaymentProviderInterface
{
    public function normalizeAmount(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public function createCheckout(SaasApiKey $apiKey, float $amount, array $context = []): array
    {
        $secret = (string) env('STRIPE_SECRET_KEY', '');
        $successUrl = (string) ($context['success_url'] ?? env('STRIPE_SUCCESS_URL', 'https://example.com/success'));
        $cancelUrl = (string) ($context['cancel_url'] ?? env('STRIPE_CANCEL_URL', 'https://example.com/cancel'));
        $currency = strtolower((string) config('payments.stripe.currency', 'usd'));
        $unitAmount = $this->normalizeAmount($amount);

        $res = Http::asForm()
            ->timeout(8)
            ->connectTimeout(5)
            ->withToken($secret)
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'line_items[0][price_data][currency]' => $currency,
                'line_items[0][price_data][unit_amount]' => $unitAmount,
                'line_items[0][price_data][product_data][name]' => 'API Key Top-up #' . $apiKey->id,
                'line_items[0][quantity]' => 1,
                'metadata[api_key_id]' => (string) $apiKey->id,
                'metadata[payment_id]' => (string) ($context['payment_id'] ?? ''),
            ]);

        if (!$res->ok()) {
            throw new \RuntimeException('Stripe checkout creation failed');
        }

        $payload = (array) $res->json();
        return [
            'provider_id' => (string) ($payload['id'] ?? ''),
            'checkout_url' => $payload['url'] ?? null,
            'raw' => $payload,
        ];
    }

    public function handleWebhook(Request $request): array
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');
        $secret = (string) env('STRIPE_WEBHOOK_SECRET', '');

        if (!$this->verifySignature($payload, $signature, $secret)) {
            return ['ok' => false, 'provider_id' => null, 'provider_event_id' => null, 'status' => null, 'amount_total' => null, 'currency' => null];
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return ['ok' => false, 'provider_id' => null, 'provider_event_id' => null, 'status' => null, 'amount_total' => null, 'currency' => null];
        }

        $type = (string) ($event['type'] ?? '');
        $session = (array) ($event['data']['object'] ?? []);
        $providerId = (string) ($session['id'] ?? '');
        $amountTotal = isset($session['amount_total']) ? (int) $session['amount_total'] : null;
        $currency = isset($session['currency']) ? strtolower((string) $session['currency']) : null;

        $status = match ($type) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => 'succeeded',
            'checkout.session.expired' => 'expired',
            'checkout.session.async_payment_failed' => 'failed',
            default => null,
        };

        return [
            'ok' => true,
            'provider_id' => $providerId !== '' ? $providerId : null,
            'provider_event_id' => isset($event['id']) ? (string) $event['id'] : null,
            'status' => $status,
            'amount_total' => $amountTotal,
            'currency' => $currency,
        ];
    }

    private function verifySignature(string $payload, string $signatureHeader, string $secret): bool
    {
        if ($payload === '' || $signatureHeader === '' || $secret === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $signatureHeader) as $p) {
            [$k, $v] = array_pad(explode('=', trim($p), 2), 2, null);
            if ($k !== null && $v !== null) {
                $parts[$k] = $v;
            }
        }
        $timestamp = $parts['t'] ?? null;
        $v1 = $parts['v1'] ?? null;
        if ($timestamp === null || $v1 === null) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        return hash_equals($expected, $v1);
    }
}
