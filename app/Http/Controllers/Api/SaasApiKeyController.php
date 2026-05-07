<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\SaasApiKey;
use App\Services\Payments\PaymentProviderInterface;
use App\Services\Payments\PaymentProviderManager;
use App\Support\PaymentWebhookCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaasApiKeyController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);
        $plain = 'sk_live_'.Str::random(40);
        $key = SaasApiKey::create([
            'name' => $data['name'],
            'api_key_hash' => SaasApiKey::hashKey($plain),
            'requests_per_minute' => 60,
            'balance' => 0,
            'cost_per_request' => 0.001,
            'is_active' => true,
        ]);

        return response()->json([
            'id' => $key->id,
            'name' => $key->name,
            'api_key' => $plain,
            'balance' => (float) $key->balance,
            'created_at' => $key->created_at?->toIso8601String(),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $q = SaasApiKey::query()->orderByDesc('id');
        $perPage = min((int) $request->input('per_page', 20), 100);
        $items = $q->paginate($perPage);

        $todayStart = now()->startOfDay();
        $usageToday = DB::table('api_usage_logs')
            ->selectRaw('api_key_id, SUM(request_count) as total_requests')
            ->where('created_at', '>=', $todayStart)
            ->groupBy('api_key_id')
            ->pluck('total_requests', 'api_key_id');

        return response()->json([
            'data' => $items->getCollection()->map(function (SaasApiKey $k) use ($usageToday) {
                return [
                    'id' => $k->id,
                    'name' => $k->name,
                    'balance' => (float) $k->balance,
                    'requests_per_minute' => (int) $k->requests_per_minute,
                    'is_active' => (bool) $k->is_active,
                    'usage_today' => (int) ($usageToday[$k->id] ?? 0),
                    'last_used_at' => $k->last_used_at?->toIso8601String(),
                    'created_at' => $k->created_at?->toIso8601String(),
                ];
            })->values()->all(),
            'meta' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $key = SaasApiKey::findOrFail($id);

        $totalRequests = (int) DB::table('api_usage_logs')
            ->where('api_key_id', $key->id)
            ->sum('request_count');

        $dailyUsage = DB::table('api_usage_logs')
            ->selectRaw('DATE(created_at) as day, SUM(request_count) as requests, SUM(request_count * ?) as cost', [(float) $key->cost_per_request])
            ->where('api_key_id', $key->id)
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at) ASC')
            ->get()
            ->map(fn ($r) => [
                'day' => (string) $r->day,
                'requests' => (int) $r->requests,
                'cost' => (float) $r->cost,
            ])
            ->values()
            ->all();

        $totalCost = (float) DB::table('api_usage_logs')
            ->where('api_key_id', $key->id)
            ->sum(DB::raw('request_count * '.(float) $key->cost_per_request));

        return response()->json([
            'id' => $key->id,
            'name' => $key->name,
            'balance' => (float) $key->balance,
            'cost_per_request' => (float) $key->cost_per_request,
            'requests_per_minute' => (int) $key->requests_per_minute,
            'is_active' => (bool) $key->is_active,
            'total_requests' => $totalRequests,
            'total_cost' => $totalCost,
            'usage_chart' => $dailyUsage,
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'created_at' => $key->created_at?->toIso8601String(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $key = SaasApiKey::findOrFail($id);
        $data = $request->validate([
            'requests_per_minute' => 'sometimes|integer|min:1|max:100000',
            'is_active' => 'sometimes|boolean',
            'regenerate' => 'sometimes|boolean',
        ]);

        $response = [];
        if (array_key_exists('requests_per_minute', $data)) {
            $key->requests_per_minute = (int) $data['requests_per_minute'];
        }
        if (array_key_exists('is_active', $data)) {
            $key->is_active = (bool) $data['is_active'];
        }
        if (! empty($data['regenerate'])) {
            $plain = 'sk_live_'.Str::random(40);
            $key->api_key_hash = SaasApiKey::hashKey($plain);
            $response['regenerated_key'] = $plain;
        }
        $key->save();

        return response()->json(array_merge([
            'id' => $key->id,
            'name' => $key->name,
            'balance' => (float) $key->balance,
            'requests_per_minute' => (int) $key->requests_per_minute,
            'is_active' => (bool) $key->is_active,
        ], $response));
    }

    public function addBalance(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.0001',
        ]);

        $updated = DB::transaction(function () use ($id, $data) {
            $key = SaasApiKey::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $key->balance = (float) $key->balance + (float) $data['amount'];
            $key->save();

            return $key;
        });

        return response()->json([
            'id' => $updated->id,
            'balance' => (float) $updated->balance,
        ]);
    }

    public function checkout(Request $request, int $id): JsonResponse
    {
        $key = SaasApiKey::findOrFail($id);
        $manager = app(PaymentProviderManager::class);
        $activeNames = $manager->getActiveProviderNames();
        $allowedProviders = implode(',', $activeNames);
        $defaultProvider = $activeNames[0] ?? 'stripe';
        $data = $request->validate([
            'provider' => 'nullable|string|in:'.($allowedProviders ?: 'stripe'),
            'amount' => 'required|numeric|min:0.01',
            'user_email' => 'nullable|email',
        ]);

        $provider = strtolower((string) ($data['provider'] ?? $defaultProvider));
        $amount = round((float) $data['amount'], 4);
        $returnToken = \Illuminate\Support\Str::random(32);
        $paymentId = DB::table('payments')->insertGetId([
            'api_key_id' => $key->id,
            'amount' => $amount,
            'provider' => $provider,
            'status' => 'pending',
            'user_email' => $data['user_email'] ?? null,
            'return_token' => $returnToken,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $providerService = $this->provider($provider);
            $checkout = $providerService->createCheckout($key, $amount, [
                'payment_id' => $paymentId,
                'return_token' => $returnToken,
            ]);
        } catch (\Throwable) {
            DB::table('payments')->where('id', $paymentId)->update(['status' => 'failed']);

            return response()->json(['error' => 'Checkout creation failed'], 422);
        }

        DB::table('payments')->where('id', $paymentId)->update([
            'provider_id' => $checkout['provider_id'] ?? null,
        ]);

        return response()->json([
            'payment_id' => $paymentId,
            'return_token' => $returnToken,
            'provider' => $provider,
            'provider_id' => $checkout['provider_id'] ?? null,
            'checkout_url' => $checkout['checkout_url'] ?? null,
        ]);
    }

    public function stripeWebhook(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return $this->providerWebhook('stripe', $request);
    }

    public function tinkoffWebhook(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        \Illuminate\Support\Facades\Log::info('tinkoff webhook', $request->all());

        if (! $request->has('PaymentId') || ! $request->has('Status')) {
            return response('OK', 200, ['Content-Type' => 'text/plain']);
        }

        $orderId = (string) $request->input('OrderId', '');
        if (! str_starts_with($orderId, 'pay_')) {
            return response('OK', 200, ['Content-Type' => 'text/plain']);
        }

        $paymentId = $request->input('PaymentId');
        $status = strtoupper((string) $request->input('Status'));
        $eventId = $paymentId && $status ? $paymentId.'_'.$status : $paymentId;

        $log = PaymentWebhookLog::create([
            'provider' => 'tinkoff',
            'provider_event_id' => $eventId,
            'payload' => $request->all(),
            'headers' => $request->headers->all(),
            'status' => 'received',
        ]);

        try {
            $this->providerWebhook('tinkoff', $request, $log);

            return response('OK', 200, ['Content-Type' => 'text/plain']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Tinkoff webhook failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $log->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            return response('OK', 200, ['Content-Type' => 'text/plain']);
        }
    }

    public function sberWebhook(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $orderNumber = (string) $request->input('orderNumber', $request->input('mdOrder', ''));
        if ($orderNumber === '' || ! str_starts_with($orderNumber, 'pay_')) {
            return response('OK', 200, ['Content-Type' => 'text/plain']);
        }

        $eventId = $orderNumber;
        $log = PaymentWebhookLog::create([
            'provider' => 'sber',
            'provider_event_id' => $eventId,
            'payload' => $request->all(),
            'headers' => $request->headers->all(),
            'status' => 'received',
        ]);

        try {
            $this->providerWebhook('sber', $request, $log);

            return response('OK', 200, ['Content-Type' => 'text/plain']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Sber webhook failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return response('OK', 200, ['Content-Type' => 'text/plain']);
        }
    }

    public function atolWebhook(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return $this->providerWebhook('atol', $request);
    }

    private const STATUS_TRANSITIONS = [
        'pending' => ['processing', 'succeeded', 'failed'],
        'processing' => ['succeeded', 'failed'],
        'succeeded' => [],
        'failed' => [],
        'expired' => [],
    ];

    private function providerWebhook(string $provider, Request $request, ?PaymentWebhookLog $webhookLog = null): \Symfony\Component\HttpFoundation\Response
    {
        $providerService = $this->provider($provider);
        $result = $providerService->handleWebhook($request);

        if (($result['ok'] ?? false) !== true) {
            if (($result['return_ok'] ?? false) && in_array($provider, ['tinkoff', 'sber'], true)) {
                return response('OK', 200, ['Content-Type' => 'text/plain']);
            }
            $status = (int) ($result['http_status'] ?? 400);

            return response()->json(['error' => 'Invalid webhook'], $status);
        }

        $providerId = (string) ($result['provider_id'] ?? '');
        $providerEventId = (string) ($result['provider_event_id'] ?? '');
        $newStatus = (string) ($result['status'] ?? '');
        if ($providerId === '' || $newStatus === '') {
            \Illuminate\Support\Facades\Log::warning('Tinkoff WEBHOOK EXIT', ['reason' => 'empty_provider_or_status', 'provider_id' => $providerId, 'new_status' => $newStatus]);

            return $this->webhookSuccessResponse($provider);
        }

        $manager = app(PaymentProviderManager::class);
        DB::transaction(function () use ($provider, $providerId, $providerEventId, $newStatus, $result, $manager, $webhookLog, $request) {
            if ($providerEventId !== '') {
                $alreadyProcessed = Payment::where('provider', $provider)
                    ->where('provider_event_id', $providerEventId)
                    ->exists();
                if ($alreadyProcessed) {
                    \Illuminate\Support\Facades\Log::warning('Tinkoff WEBHOOK EXIT', ['reason' => 'idempotent', 'provider_event_id' => $providerEventId]);
                    $this->markWebhookProcessed($webhookLog);

                    return;
                }
            }

            $payment = $this->findPaymentForWebhook($provider, $providerId, $result);
            if (! $payment) {
                \Illuminate\Support\Facades\Log::warning('Tinkoff WEBHOOK EXIT', ['reason' => 'payment_not_found', 'provider_id' => $providerId, 'order_id' => $result['order_id'] ?? null]);

                return;
            }

            $paymentIdForLock = $payment->id;
            $payment = Payment::where('id', $payment->id)->lockForUpdate()->first();
            if (! $payment) {
                \Illuminate\Support\Facades\Log::warning('Tinkoff WEBHOOK EXIT', ['reason' => 'payment_lock_fail', 'payment_id' => $paymentIdForLock]);

                return;
            }

            // Rate limit only when a webhook already processed this payment (provider_event_id set).
            // Skip when provider_event_id is null — recent updated_at may be from checkout, not duplicate webhook.
            if ($payment->provider_event_id !== null && $payment->updated_at && $payment->updated_at->diffInSeconds(now()) < 1) {
                \Illuminate\Support\Facades\Log::warning('Tinkoff WEBHOOK EXIT', ['reason' => 'rate_limit', 'payment_id' => $payment->id]);
                $this->markWebhookProcessed($webhookLog);

                return;
            }

            $allowed = self::STATUS_TRANSITIONS[$payment->status] ?? [];
            if (! in_array($newStatus, $allowed, true)) {
                \Illuminate\Support\Facades\Log::warning('Tinkoff WEBHOOK EXIT', ['reason' => 'status_skip', 'payment_id' => $payment->id, 'current_status' => $payment->status, 'new_status' => $newStatus, 'allowed' => $allowed]);
                $this->markWebhookProcessed($webhookLog);

                return;
            }

            $incomingAmount = (int) ($result['amount_total'] ?? $request->input('Amount') ?? -1);
            $expectedAmount = (int) round((float) $payment->amount * 100);
            \Illuminate\Support\Facades\Log::info('Tinkoff WEBHOOK AMOUNT CHECK', [
                'incoming' => $incomingAmount,
                'expected' => $expectedAmount,
                'payment_amount' => (float) $payment->amount,
            ]);
            if ($incomingAmount <= 0) {
                \Illuminate\Support\Facades\Log::warning('Tinkoff WEBHOOK EXIT', ['reason' => 'amount_zero', 'incoming' => $incomingAmount]);
                $this->markWebhookProcessed($webhookLog);

                return;
            }
            if ($incomingAmount !== $expectedAmount) {
                \Illuminate\Support\Facades\Log::warning('Tinkoff WEBHOOK EXIT', ['reason' => 'amount_mismatch', 'incoming' => $incomingAmount, 'expected' => $expectedAmount]);
                throw new \RuntimeException('Amount mismatch');
            }

            if (in_array($newStatus, ['failed', 'expired'], true)) {
                $payment->update([
                    'status' => $newStatus,
                    'provider_event_id' => $providerEventId ?: $payment->provider_event_id,
                ]);
                $this->markWebhookProcessed($webhookLog);

                return;
            }

            $providerRecord = $manager->getProviderRecord($provider);
            $configured = $providerRecord->config['currency'] ?? null;
            if ($configured === null || $configured === '') {
                $configured = config(
                    'payments.'.$provider.'.currency',
                    $provider === 'stripe' ? 'usd' : 'rub'
                );
            }
            $expectedCurrency = PaymentWebhookCurrency::normalize((string) $configured);
            if ($expectedCurrency === '') {
                $expectedCurrency = $provider === 'stripe' ? 'usd' : 'rub';
            }
            $incomingCurrency = PaymentWebhookCurrency::normalize((string) ($result['currency'] ?? ''));
            if ($incomingCurrency === '' && in_array($provider, ['tinkoff', 'sber'], true)) {
                $incomingCurrency = 'rub';
            }
            if ($incomingCurrency !== $expectedCurrency) {
                \Illuminate\Support\Facades\Log::warning('Tinkoff WEBHOOK EXIT', ['reason' => 'currency_mismatch', 'incoming' => $incomingCurrency, 'expected' => $expectedCurrency]);
                $payment->update([
                    'status' => 'failed',
                    'provider_event_id' => $providerEventId ?: $payment->provider_event_id,
                ]);
                $this->markWebhookProcessed($webhookLog);

                return;
            }

            \Illuminate\Support\Facades\Log::info('Tinkoff WEBHOOK SUCCESS', ['payment_id' => $payment->id, 'amount' => $payment->amount]);
            if ($payment->customer_order_id !== null) {
                CustomerOrder::query()->whereKey($payment->customer_order_id)->update([
                    'payment_status' => 'paid',
                    'status' => 'confirmed',
                    'paid_at' => now(),
                ]);
            } else {
                $key = $payment->api_key_id !== null
                    ? SaasApiKey::query()->whereKey($payment->api_key_id)->lockForUpdate()->first()
                    : null;
                if ($key) {
                    $key->balance = (float) $key->balance + (float) $payment->amount;
                    $key->save();
                }
            }

            $payment->update([
                'status' => 'succeeded',
                'provider_event_id' => $providerEventId ?: $payment->provider_event_id,
                'provider_id' => $providerId ?: $payment->provider_id,
            ]);

            $paymentIdForAtol = $payment->id;
            if (! $payment->atol_uuid && $payment->atol_status !== 'processing') {
                $payment->update(['atol_status' => 'processing']);
                DB::afterCommit(function () use ($paymentIdForAtol) {
                    \App\Jobs\SendAtolReceiptJob::dispatch($paymentIdForAtol);
                });
            }

            $this->markWebhookProcessed($webhookLog);
        });

        return $this->webhookSuccessResponse($provider);
    }

    private function findPaymentForWebhook(string $provider, string $providerId, array $result): ?Payment
    {
        $payment = Payment::where('provider', $provider)
            ->where('provider_id', $providerId)
            ->lockForUpdate()
            ->first();

        if (! $payment && in_array($provider, ['tinkoff', 'sber']) && ! empty($result['order_id'])) {
            $orderId = $result['order_id'];
            if (preg_match('/^pay_(\d+)$/', $orderId, $m)) {
                $payment = Payment::where('provider', $provider)
                    ->where('id', (int) $m[1])
                    ->lockForUpdate()
                    ->first();
                // Do NOT update provider_id here — it would bump updated_at and trigger rate_limit.
                // provider_id is set in the final success update.
            }
        }

        return $payment;
    }

    private function markWebhookProcessed(?PaymentWebhookLog $webhookLog): void
    {
        if ($webhookLog) {
            $webhookLog->update(['status' => 'processed']);
        }
    }

    private function webhookSuccessResponse(string $provider): \Symfony\Component\HttpFoundation\Response
    {
        if (in_array($provider, ['tinkoff', 'sber'], true)) {
            return response('OK', 200, ['Content-Type' => 'text/plain']);
        }

        return response()->json(['received' => true]);
    }

    private function provider(string $provider): PaymentProviderInterface
    {
        return app(PaymentProviderManager::class)->getProvider($provider);
    }

    public function webhookReplay(Request $request, int $id): JsonResponse
    {
        $log = PaymentWebhookLog::findOrFail($id);
        if ($log->provider !== 'tinkoff') {
            return response()->json(['error' => 'Replay only supported for tinkoff'], 400);
        }

        $replayRequest = Request::create('/', 'POST', $log->payload, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        try {
            $this->providerWebhook('tinkoff', $replayRequest, null);

            return response()->json(['message' => 'Replay completed', 'log_id' => $id]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Replay failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
