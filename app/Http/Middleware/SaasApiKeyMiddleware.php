<?php

namespace App\Http\Middleware;

use App\Models\SaasApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class SaasApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $incomingKey = trim((string) $request->header('X-API-KEY', ''));
        if ($incomingKey === '') {
            return response()->json(['error' => 'Missing X-API-KEY'], 401);
        }

        $hashed = SaasApiKey::hashKey($incomingKey);
        $apiKey = SaasApiKey::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (SaasApiKey $k) => hash_equals($k->api_key_hash, $hashed));

        if ($apiKey === null) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        $limitKey = 'saas:' . $apiKey->id . ':' . $request->ip() . ':' . trim($request->path(), '/');
        $max = max(1, (int) $apiKey->requests_per_minute);
        if (RateLimiter::tooManyAttempts($limitKey, $max)) {
            return response()
                ->json(['error' => 'Rate limit exceeded'], 429)
                ->header('X-RateLimit-Remaining', (string) RateLimiter::remaining($limitKey, $max))
                ->header('X-Balance-Remaining', (string) $apiKey->balance);
        }
        RateLimiter::hit($limitKey, 60);
        $remaining = RateLimiter::remaining($limitKey, $max);

        $current = SaasApiKey::query()->whereKey($apiKey->id)->first();
        if ($current === null || (float) $current->balance <= 0) {
            return response()
                ->json(['error' => 'Insufficient balance', 'error_code' => 'insufficient_balance'], 403)
                ->header('X-RateLimit-Remaining', (string) $remaining)
                ->header('X-Balance-Remaining', (string) ($current?->balance ?? 0));
        }

        $request->attributes->set('saas_api_key', $current);

        $startedAt = microtime(true);
        $response = $next($request);
        $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

        $balanceForHeader = (float) $current->balance;
        if ($response->getStatusCode() < 500) {
            $now = now();
            $charged = DB::transaction(function () use ($current, $now) {
                $fresh = SaasApiKey::query()->whereKey($current->id)->lockForUpdate()->first();
                if ($fresh === null) {
                    return null;
                }
                $cost = (float) $fresh->cost_per_request;
                $balance = (float) $fresh->balance;
                if ($balance <= 0 || $balance < $cost) {
                    return ['blocked' => true, 'balance' => $balance];
                }
                $fresh->balance = max(0, $balance - $cost);
                if ($fresh->last_used_at === null || $fresh->last_used_at->lte($now->copy()->subSeconds(60))) {
                    $fresh->last_used_at = $now;
                }
                $fresh->save();
                return ['blocked' => false, 'balance' => (float) $fresh->balance];
            });

            if ($charged === null || $charged['blocked'] === true) {
                return response()
                    ->json(['error' => 'Insufficient balance', 'error_code' => 'insufficient_balance'], 403)
                    ->header('X-RateLimit-Remaining', (string) $remaining)
                    ->header('X-Balance-Remaining', (string) ($charged['balance'] ?? 0));
            }
            $balanceForHeader = (float) $charged['balance'];
        }

        try {
            DB::table('api_usage_logs')->insert([
                'api_key_id' => $current->id,
                'endpoint' => '/' . trim($request->path(), '/'),
                'request_count' => 1,
                'response_time' => $responseTimeMs,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
        }

        return $response
            ->header('X-RateLimit-Remaining', (string) $remaining)
            ->header('X-Balance-Remaining', (string) $balanceForHeader);
    }
}
