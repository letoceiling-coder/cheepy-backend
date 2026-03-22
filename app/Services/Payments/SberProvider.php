<?php

namespace App\Services\Payments;

use App\Models\SaasApiKey;
use Illuminate\Http\Request;

class SberProvider implements PaymentProviderInterface
{
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
        throw new \RuntimeException('Sber provider is not implemented');
    }

    public function handleWebhook(Request $request): array
    {
        return ['ok' => false, 'provider_id' => null, 'provider_event_id' => null, 'status' => null, 'amount_total' => null, 'currency' => null];
    }
}
