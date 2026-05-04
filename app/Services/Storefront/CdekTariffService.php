<?php

namespace App\Services\Storefront;

use App\Models\DeliveryIntegration;
use App\Services\Delivery\CdekOAuthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Расчёт тарифов СДЭК (калькулятор v2) для витрины.
 *
 * @see https://apidoc.cdek.ru/
 */
class CdekTariffService
{
    public function __construct(private CdekOAuthService $oauth)
    {
    }

    /**
     * @return array{ok: bool, message?: string, quote?: array<string, mixed>}
     */
    public function quoteDoorToDoor(
        int $fromCityCode,
        string $toCity,
        ?string $toPostalCode,
        int $weightG,
        int $lengthCm,
        int $widthCm,
        int $heightCm,
    ): array {
        $integration = DeliveryIntegration::query()->where('name', 'cdek')->first();
        $config = $integration?->config ?? [];
        if (! $integration?->is_active || empty($config['client_id']) || empty($config['client_secret'])) {
            return ['ok' => false, 'message' => 'СДЭК не подключён'];
        }

        $env = ($config['environment'] ?? CdekOAuthService::ENV_PRODUCTION) === CdekOAuthService::ENV_INTEGRATION
            ? CdekOAuthService::ENV_INTEGRATION
            : CdekOAuthService::ENV_PRODUCTION;

        $token = $this->requestAccessToken((string) $config['client_id'], (string) $config['client_secret'], $env);
        if ($token === null) {
            return ['ok' => false, 'message' => 'Не удалось авторизоваться в СДЭК'];
        }

        $toLocation = $this->resolveToLocation($token, $env, $toCity, $toPostalCode);
        if ($toLocation === null) {
            return ['ok' => false, 'message' => 'Не удалось определить получателя в справочнике СДЭК'];
        }

        $base = $this->oauth->apiBase($env);
        $body = [
            'type' => 1,
            'currency' => 1,
            'lang' => 'ru',
            'from_location' => ['code' => $fromCityCode],
            'to_location' => $toLocation,
            'packages' => [
                array_filter([
                    'weight' => max(1, $weightG),
                    'length' => $lengthCm > 0 ? $lengthCm : null,
                    'width' => $widthCm > 0 ? $widthCm : null,
                    'height' => $heightCm > 0 ? $heightCm : null,
                ], fn ($v) => $v !== null),
            ],
        ];

        try {
            $res = Http::withToken($token)
                ->acceptJson()
                ->timeout(25)
                ->post($base.'/v2/calculator/tarifflist', $body);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'СДЭК: '.$e->getMessage()];
        }

        if (! $res->successful()) {
            $msg = 'СДЭК HTTP '.$res->status();
            $j = $res->json();
            if (is_array($j)) {
                $sub = $j['message'] ?? $j['error'] ?? null;
                if (is_string($sub) && $sub !== '') {
                    $msg .= ': '.$sub;
                }
            }

            return ['ok' => false, 'message' => $msg];
        }

        $json = $res->json();
        $rows = [];
        if (is_array($json)) {
            $rows = $json['tariff_codes'] ?? $json['tariffs'] ?? [];
            if (! is_array($rows)) {
                $rows = [];
            }
        }

        $best = null;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mode = (int) ($row['delivery_mode'] ?? 0);
            if ($mode !== 1) {
                continue;
            }
            $sum = (float) ($row['delivery_sum'] ?? $row['deliverySum'] ?? 0);
            if ($sum <= 0) {
                continue;
            }
            if ($best === null || $sum < (float) ($best['delivery_sum'] ?? $best['deliverySum'] ?? PHP_FLOAT_MAX)) {
                $best = $row;
            }
        }

        if ($best === null) {
            return ['ok' => false, 'message' => 'Нет тарифа «дверь—дверь» для выбранного адреса'];
        }

        $sum = (float) ($best['delivery_sum'] ?? $best['deliverySum'] ?? 0);
        $pMin = (int) ($best['period_min'] ?? $best['periodMin'] ?? 0);
        $pMax = (int) ($best['period_max'] ?? $best['periodMax'] ?? 0);
        $name = (string) ($best['tariff_name'] ?? $best['tariffName'] ?? 'Курьерская доставка');

        return [
            'ok' => true,
            'quote' => [
                'integration' => 'cdek',
                'provider_title' => 'СДЭК',
                'service_code' => (string) ($best['tariff_code'] ?? $best['tariffCode'] ?? ''),
                'service_name' => $name,
                'delivery_mode' => 'door_door',
                'price_rub' => round($sum, 2),
                'period_min_days' => $pMin,
                'period_max_days' => $pMax > 0 ? $pMax : $pMin,
            ],
        ];
    }

    /**
     * @return array<string, int|string>|null
     */
    private function resolveToLocation(string $token, string $env, string $toCity, ?string $toPostalCode): ?array
    {
        $pc = $toPostalCode !== null ? preg_replace('/\D/', '', $toPostalCode) : '';
        if (strlen($pc) === 6) {
            return ['postal_code' => $pc, 'country_code' => 'RU'];
        }

        $code = $this->resolveCityCode($token, $env, $toCity);
        if ($code !== null) {
            return ['code' => $code, 'country_code' => 'RU'];
        }

        return null;
    }

    private function resolveCityCode(string $token, string $env, string $city): ?int
    {
        $city = trim($city);
        if ($city === '') {
            return null;
        }
        $key = 'cdek:citycode:'.md5(mb_strtolower($city));
        $cached = Cache::get($key);
        if (is_int($cached)) {
            return $cached > 0 ? $cached : null;
        }

        $base = $this->oauth->apiBase($env);
        try {
            $res = Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->get($base.'/v2/location/cities', [
                    'country_codes' => 'RU',
                    'size' => 5,
                    'lang' => 'rus',
                    'city' => $city,
                ]);
        } catch (\Throwable) {
            Cache::put($key, 0, 300);

            return null;
        }

        if (! $res->successful()) {
            Cache::put($key, 0, 300);

            return null;
        }

        $list = $res->json();
        if (! is_array($list) || $list === []) {
            Cache::put($key, 0, 3600);

            return null;
        }

        $first = $list[0] ?? null;
        if (! is_array($first)) {
            Cache::put($key, 0, 3600);

            return null;
        }
        $code = (int) ($first['code'] ?? 0);
        if ($code <= 0) {
            Cache::put($key, 0, 3600);

            return null;
        }
        Cache::put($key, $code, 86400);

        return $code;
    }

    private function requestAccessToken(string $clientId, string $clientSecret, string $env): ?string
    {
        $cacheKey = 'cdek:oauth:'.md5($clientId.'|'.$env);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
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
        } catch (\Throwable) {
            return null;
        }

        $body = $res->json();
        $token = is_array($body) ? (string) ($body['access_token'] ?? '') : '';
        if (! $res->successful() || $token === '') {
            return null;
        }

        $ttl = max(60, (int) ($body['expires_in'] ?? 3600) - 60);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }
}
