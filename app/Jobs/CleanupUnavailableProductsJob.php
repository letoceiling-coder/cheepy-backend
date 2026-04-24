<?php

namespace App\Jobs;

use App\Models\Product;
use App\Support\ParserJobOptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Периодическая проверка «умер ли донор-товар по прямому URL».
 *
 * Работает как страховочный механизм: availability-pass парсера («кого не видели в
 * обходе категории → is_relevant=false») не ловит case, когда товар пропал из
 * листинга, но ещё жив у донора под старым URL, или наоборот — лежит в БД со
 * старой категорией, которая давно не парсится. Этот job — последний рубеж.
 *
 * Берёт до $limit товаров с самой старой relevance_checked_at (или NULL),
 * делает HEAD /odejda/{external_id} c прокси-настройками парсера, обновляет
 * is_relevant / relevance_checked_at. status НЕ меняет (решение отдаём CRM/
 * витрине — скрывать или нет).
 *
 * Scheduler вызывает его hourly; rate-limit определяется min/max delay настройкой
 * парсера, но не чаще 1 req/sec — иначе донор начнёт банить.
 */
class CleanupUnavailableProductsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $limit = 100)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'cleanup-unavailable-products';
    }

    public function handle(): void
    {
        $runtime = ParserJobOptions::runtimePayload(\App\Models\ParserSetting::current());
        $http = $runtime['http_client'] ?? [];
        $baseUrl = rtrim((string) ($http['base_url'] ?? 'https://sadovodbaza.ru'), '/');
        $timeout = (int) ($http['timeout_seconds'] ?? 15);
        $delayMinMs = (int) ($http['delay_min_ms'] ?? 1000);
        $useProxy = (bool) ($http['use_proxy'] ?? false);
        $proxyUrl = (string) ($http['proxy_url'] ?? '');
        $userAgents = (array) ($http['user_agents'] ?? []);
        $ua = $userAgents[array_rand($userAgents)] ?? 'Mozilla/5.0';

        // Берём товары, у которых relevance давно не проверялась (> 7 дней) или не проверялась никогда.
        // Сортируем по самому старому relevance_checked_at — выравниваем покрытие.
        $products = Product::query()
            ->whereNotNull('external_id')
            ->where(function ($q) {
                $q->whereNull('relevance_checked_at')
                    ->orWhere('relevance_checked_at', '<', now()->subDays(7));
            })
            ->orderByRaw('relevance_checked_at IS NULL DESC')
            ->orderBy('relevance_checked_at', 'asc')
            ->limit($this->limit)
            ->get(['id', 'external_id', 'is_relevant']);

        if ($products->isEmpty()) {
            return;
        }

        $alive = 0;
        $dead = 0;
        $failed = 0;

        foreach ($products as $product) {
            $url = $baseUrl . '/odejda/' . $product->external_id;

            $client = Http::withHeaders([
                'User-Agent' => $ua,
                'Accept' => 'text/html,application/xhtml+xml',
            ])->timeout($timeout);

            if ($useProxy && $proxyUrl !== '') {
                $client = $client->withOptions(['proxy' => $proxyUrl]);
            }

            try {
                /** @var Response $response */
                $response = $client->head($url);
                $status = $response->status();

                if ($status >= 200 && $status < 400) {
                    $product->update([
                        'is_relevant' => true,
                        'relevance_checked_at' => now(),
                    ]);
                    $alive++;
                } elseif ($status === 404 || $status === 410) {
                    $product->update([
                        'is_relevant' => false,
                        'relevance_checked_at' => now(),
                    ]);
                    $dead++;
                } else {
                    // 403/429/5xx → не уверены, не трогаем is_relevant, только touch
                    // relevance_checked_at — иначе мы бесконечно будем возвращаться к нему.
                    $product->update(['relevance_checked_at' => now()]);
                    $failed++;
                }
            } catch (\Throwable $e) {
                Log::warning('CleanupUnavailableProductsJob request failed', [
                    'product_id' => $product->id,
                    'external_id' => $product->external_id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }

            // Rate-limit: не чаще чем delay_min_ms между запросами.
            if ($delayMinMs > 0) {
                usleep($delayMinMs * 1000);
            }
        }

        Log::info('CleanupUnavailableProductsJob finished', [
            'checked' => $products->count(),
            'alive' => $alive,
            'dead' => $dead,
            'failed' => $failed,
        ]);
    }
}
