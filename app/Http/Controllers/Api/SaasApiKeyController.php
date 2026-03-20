<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaasApiKey;
use App\Services\Payments\PaymentProviderInterface;
use App\Services\Payments\SberProvider;
use App\Services\Payments\StripeProvider;
use App\Services\Payments\TinkoffProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaasApiKeyController extends Controller
{
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
            ->sum(DB::raw('request_count * ' . (float) $key->cost_per_request));

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
        if (!empty($data['regenerate'])) {
            $plain = 'sk_live_' . Str::random(40);
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
        $data = $request->validate([
            'provider' => 'nullable|string|in:stripe,tinkoff,sber',
            'amount' => 'required|numeric|min:0.01',
            'success_url' => 'nullable|url',
            'cancel_url' => 'nullable|url',
        ]);

        $provider = strtolower((string) ($data['provider'] ?? 'stripe'));
        $amount = round((float) $data['amount'], 4);
        $paymentId = DB::table('payments')->insertGetId([
            'api_key_id' => $key->id,
            'amount' => $amount,
            'provider' => $provider,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $providerService = $this->provider($provider);
            $checkout = $providerService->createCheckout($key, $amount, [
                'success_url' => $data['success_url'] ?? null,
                'cancel_url' => $data['cancel_url'] ?? null,
                'payment_id' => $paymentId,
            ]);
        } catch (\Throwable) {
            DB::table('payments')->where('id', $paymentId)->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);
            return response()->json(['error' => 'Checkout creation failed'], 422);
        }

        DB::table('payments')->where('id', $paymentId)->update([
            'provider_id' => $checkout['provider_id'] ?? null,
            'updated_at' => now(),
        ]);

        return response()->json([
            'payment_id' => $paymentId,
            'provider' => $provider,
            'provider_id' => $checkout['provider_id'] ?? null,
            'checkout_url' => $checkout['checkout_url'] ?? null,
        ]);
    }

    public function stripeWebhook(Request $request): JsonResponse
    {
        return $this->providerWebhook('stripe', $request);
    }

    public function tinkoffWebhook(Request $request): JsonResponse
    {
        return $this->providerWebhook('tinkoff', $request);
    }

    public function sberWebhook(Request $request): JsonResponse
    {
        return $this->providerWebhook('sber', $request);
    }

    private function providerWebhook(string $provider, Request $request): JsonResponse
    {
        $providerService = $this->provider($provider);
        $result = $providerService->handleWebhook($request);
        if (($result['ok'] ?? false) !== true) {
            return response()->json(['error' => 'Invalid webhook'], 400);
        }

        $providerId = (string) ($result['provider_id'] ?? '');
        $providerEventId = (string) ($result['provider_event_id'] ?? '');
        $status = (string) ($result['status'] ?? '');
        if ($providerId === '' || $status === '') {
            return response()->json(['received' => true]);
        }

        DB::transaction(function () use ($provider, $providerId, $providerEventId, $status, $result) {
            if ($providerEventId !== '') {
                $alreadyProcessed = DB::table('payments')
                    ->where('provider', $provider)
                    ->where('provider_event_id', $providerEventId)
                    ->exists();
                if ($alreadyProcessed) {
                    return;
                }
            }

            $payment = DB::table('payments')
                ->where('provider', $provider)
                ->where('provider_id', $providerId)
                ->lockForUpdate()
                ->first();
            if (!$payment) {
                return;
            }
            if ($payment->status === 'succeeded') {
                return;
            }

            if (in_array($status, ['failed', 'expired'], true)) {
                DB::table('payments')->where('id', $payment->id)->update([
                    'status' => $status,
                    'provider_event_id' => $providerEventId !== '' ? $providerEventId : $payment->provider_event_id,
                    'updated_at' => now(),
                ]);
                return;
            }

            $providerService = $this->provider($provider);
            $expectedAmountTotal = $providerService->normalizeAmount((float) $payment->amount);
            $incomingAmountTotal = (int) ($result['amount_total'] ?? -1);
            $incomingCurrency = strtolower((string) ($result['currency'] ?? ''));
            $expectedCurrency = strtolower((string) config("payments.{$provider}.currency", 'usd'));

            if ($incomingAmountTotal !== $expectedAmountTotal || $incomingCurrency !== $expectedCurrency) {
                DB::table('payments')->where('id', $payment->id)->update([
                    'status' => 'failed',
                    'provider_event_id' => $providerEventId !== '' ? $providerEventId : $payment->provider_event_id,
                    'updated_at' => now(),
                ]);
                return;
            }

            $key = SaasApiKey::query()->whereKey((int) $payment->api_key_id)->lockForUpdate()->first();
            if ($key) {
                $key->balance = (float) $key->balance + (float) $payment->amount;
                $key->save();
            }

            DB::table('payments')->where('id', $payment->id)->update([
                'status' => 'succeeded',
                'provider_event_id' => $providerEventId !== '' ? $providerEventId : $payment->provider_event_id,
                'updated_at' => now(),
            ]);
        });

        return response()->json(['received' => true]);
    }

    private function provider(string $provider): PaymentProviderInterface
    {
        return match ($provider) {
            'stripe' => app(StripeProvider::class),
            'tinkoff' => app(TinkoffProvider::class),
            'sber' => app(SberProvider::class),
            default => throw new \RuntimeException('Unsupported provider'),
        };
    }
}
