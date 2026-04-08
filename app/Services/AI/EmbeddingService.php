<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class EmbeddingService
{
    private const CIRCUIT_OPEN_UNTIL_KEY = 'ai_embedding:circuit:open_until';
    private const CIRCUIT_ERROR_COUNT_KEY = 'ai_embedding:circuit:error_count';

    /**
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        $this->assertCircuitClosed();
        $this->assertDailyBudgetAvailable();

        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new \RuntimeException('OpenAI API key is not configured (services.openai.api_key).');
        }

        $model = (string) config('services.openai.embedding_model', 'text-embedding-3-small');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');

        $attempts = 0;
        $lastError = null;
        $response = null;
        while ($attempts < 3) {
            $attempts++;
            try {
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->asJson()
                    ->timeout((int) config('services.openai.timeout', 20))
                    ->post($baseUrl.'/embeddings', [
                        'model' => $model,
                        'input' => $text,
                    ]);
                if ($response->successful()) {
                    break;
                }
                $lastError = new \RuntimeException(
                    'OpenAI embeddings request failed: HTTP '.$response->status().' '.$response->body()
                );
            } catch (\Throwable $e) {
                $lastError = $e;
            }

            if ($attempts < 3) {
                usleep(250_000 * $attempts);
            }
        }

        if ($response === null || ! $response->successful()) {
            $this->registerEmbeddingFailure();
            throw $lastError ?? new \RuntimeException('OpenAI embeddings request failed.');
        }

        $embedding = $response->json('data.0.embedding');
        if (! is_array($embedding)) {
            $this->registerEmbeddingFailure();
            throw new \RuntimeException('OpenAI embeddings response has invalid payload.');
        }

        $this->resetEmbeddingFailureCounter();
        return array_map(static fn ($v): float => (float) $v, $embedding);
    }

    private function assertDailyBudgetAvailable(): void
    {
        $limit = max(0, (int) config('services.openai.daily_limit', 10000));
        if ($limit === 0) {
            return;
        }

        $key = 'ai_embedding:requests:'.now()->toDateString();
        $count = Cache::increment($key);
        if ($count === 1) {
            Cache::put($key, 1, now()->endOfDay());
        }

        if ((int) $count > $limit) {
            throw new EmbeddingLimitExceededException(
                "Embedding daily limit reached: {$count}/{$limit}"
            );
        }
    }

    private function assertCircuitClosed(): void
    {
        $openUntil = (int) Cache::get(self::CIRCUIT_OPEN_UNTIL_KEY, 0);
        if ($openUntil > time()) {
            throw new EmbeddingLimitExceededException(
                'Embedding circuit breaker is open until '.date('c', $openUntil)
            );
        }
    }

    private function registerEmbeddingFailure(): void
    {
        $count = (int) Cache::increment(self::CIRCUIT_ERROR_COUNT_KEY);
        if ($count === 1) {
            Cache::put(self::CIRCUIT_ERROR_COUNT_KEY, 1, now()->addMinutes(30));
        }

        if ($count >= 5) {
            Cache::put(self::CIRCUIT_OPEN_UNTIL_KEY, time() + 300, now()->addMinutes(5));
            Cache::put(self::CIRCUIT_ERROR_COUNT_KEY, 0, now()->addMinutes(30));
        }
    }

    private function resetEmbeddingFailureCounter(): void
    {
        Cache::forget(self::CIRCUIT_ERROR_COUNT_KEY);
    }
}

