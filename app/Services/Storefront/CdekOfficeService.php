<?php

namespace App\Services\Storefront;

use App\Models\DeliveryIntegration;
use App\Services\Delivery\CdekOAuthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CdekOfficeService
{
    public function __construct(private CdekOAuthService $oauth)
    {
    }

    /**
     * @return array{enabled: bool, data: array<int, array<string, mixed>>, message?: string}
     */
    public function search(array $filters): array
    {
        $integration = DeliveryIntegration::query()->where('name', 'cdek')->first();
        $config = $integration?->config ?? [];
        if (! $integration?->is_active || empty($config['client_id']) || empty($config['client_secret'])) {
            return ['enabled' => false, 'data' => [], 'message' => 'Интеграция СДЭК не активна'];
        }

        $env = ($config['environment'] ?? CdekOAuthService::ENV_PRODUCTION) === CdekOAuthService::ENV_INTEGRATION
            ? CdekOAuthService::ENV_INTEGRATION
            : CdekOAuthService::ENV_PRODUCTION;
        $tokenResult = $this->requestAccessToken((string) $config['client_id'], (string) $config['client_secret'], $env);
        if (! $tokenResult['token']) {
            return ['enabled' => true, 'data' => [], 'message' => $tokenResult['message'] ?? 'СДЭК недоступен'];
        }

        $query = array_filter([
            'city_code' => $filters['city_code'] ?? null,
            'postal_code' => $filters['postal_code'] ?? null,
            'country_code' => $filters['country_code'] ?? 'RU',
            'type' => $filters['type'] ?? 'PVZ',
            'allowed_cod' => $filters['allowed_cod'] ?? null,
            'weight_max' => $filters['weight_max'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $cacheKey = 'cdek:offices:'.md5(json_encode($query, JSON_UNESCAPED_UNICODE));
        $data = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($env, $tokenResult, $query) {
            $res = Http::withToken($tokenResult['token'])
                ->acceptJson()
                ->timeout(20)
                ->get($this->oauth->apiBase($env).'/v2/deliverypoints', $query);
            if (! $res->successful()) {
                return [];
            }
            $rows = $res->json();
            return is_array($rows) ? $rows : [];
        });

        return ['enabled' => true, 'data' => array_values(array_map(fn ($row) => $this->mapOffice((array) $row), $data))];
    }

    /**
     * @return array{token: ?string, message?: string}
     */
    private function requestAccessToken(string $clientId, string $clientSecret, string $env): array
    {
        $cacheKey = 'cdek:oauth:'.md5($clientId.'|'.$env);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return ['token' => $cached];
        }

        try {
            $res = Http::asForm()
                ->acceptJson()
                ->timeout(20)
                ->post($this->oauth->oauthTokenUrl($env), [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);
        } catch (\Throwable $e) {
            return ['token' => null, 'message' => $e->getMessage()];
        }

        $body = $res->json();
        $token = is_array($body) ? (string) ($body['access_token'] ?? '') : '';
        if (! $res->successful() || $token === '') {
            return ['token' => null, 'message' => 'Не удалось получить токен СДЭК'];
        }

        $ttl = max(60, (int) ($body['expires_in'] ?? 3600) - 60);
        Cache::put($cacheKey, $token, $ttl);

        return ['token' => $token];
    }

    private function mapOffice(array $row): array
    {
        $location = is_array($row['location'] ?? null) ? $row['location'] : [];

        return [
            'provider' => 'cdek',
            'office_code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'city' => (string) ($location['city'] ?? ''),
            'address' => (string) ($location['address_full'] ?? $location['address'] ?? ''),
            'lat' => isset($location['latitude']) ? (float) $location['latitude'] : null,
            'lng' => isset($location['longitude']) ? (float) $location['longitude'] : null,
            'work_time' => (string) ($row['work_time'] ?? ''),
            'raw' => $row,
        ];
    }
}
