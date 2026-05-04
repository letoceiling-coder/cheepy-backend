<?php

namespace App\Services\Payments;

use App\Models\SaasApiKey;
use Illuminate\Http\Request;

interface PaymentProviderInterface
{
    public function normalizeAmount(float $amount): int;

    /**
     * @return array{provider_id:string,checkout_url:?string,raw:array}
     */
    public function createCheckout(?SaasApiKey $apiKey, float $amount, array $context = []): array;

    /**
     * @return array{
     *   ok:bool,
     *   provider_id:?string,
     *   provider_event_id:?string,
     *   status:?string,
     *   amount_total:?int,
     *   currency:?string
     * }
     */
    public function handleWebhook(Request $request): array;
}
