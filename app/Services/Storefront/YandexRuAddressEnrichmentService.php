<?php

namespace App\Services\Storefront;

use App\Models\DeliveryIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Автодополнение российского адреса: индекс и уточнение город/регион через HTTP Geocoder Яндекс.Карт
 * при активном ключе интеграции yandex_maps (тот же api_key что и подсказки).
 */
class YandexRuAddressEnrichmentService
{
    private const GEO_URL = 'https://geocode-maps.yandex.ru/1.x';

    /**
     * Подстановка индекса и пропусков (город, регион, координаты), если пользователь их не указал.
     *
     * @param  array{label?: ?string, country?: ?string, region?: ?string, city: string, postal_code?: ?string, line1: string, line2?: ?string, lat?: ?numeric, lng?: ?numeric, source?: ?string, is_default?: ?bool, provider_payload?: ?array}  $data
     * @return array<string, mixed>
     */
    public function enrichValidatedAddress(array $data): array
    {
        $country = trim((string) ($data['country'] ?? ''));
        if ($country !== '' && strcasecmp($country, 'Россия') !== 0 && strcasecmp($country, 'Russia') !== 0 && strcasecmp($country, 'RU') !== 0) {
            return $data;
        }

        $digits = preg_replace('/\D/', '', (string) ($data['postal_code'] ?? ''));
        if (strlen($digits) === 6) {
            $data['postal_code'] = $digits;

            return $data;
        }

        $row = DeliveryIntegration::query()->where('name', 'yandex_maps')->first();
        $apiKey = trim((string) (($row->config ?? [])['api_key'] ?? ''));
        if (! $row?->is_active || $apiKey === '') {
            return $data;
        }

        $city = trim((string) ($data['city'] ?? ''));
        $line1 = trim((string) ($data['line1'] ?? ''));
        if ($city === '' || $line1 === '') {
            return $data;
        }

        $query = implode(', ', array_filter([$city, $line1, 'Россия'], fn ($s) => $s !== ''));

        $cacheKey = 'yandex_geocode_addr:'.md5(mb_strtolower($query));
        $geo = Cache::remember($cacheKey, 86400, function () use ($apiKey, $query) {
            return $this->geocodeRequest($apiKey, $query);
        });

        if ($geo === null) {
            return $data;
        }

        $pc = preg_replace('/\D/', '', (string) ($geo['postal_code'] ?? ''));
        if (strlen($pc) === 6) {
            $data['postal_code'] = $pc;
        }

        if (($data['region'] ?? null) === null || trim((string) $data['region']) === '') {
            $region = trim((string) ($geo['region'] ?? ''));
            if ($region !== '') {
                $data['region'] = $region;
            }
        }

        $refCity = trim((string) ($geo['locality'] ?? ''));
        if ($refCity !== '' && $refCity !== $city) {
            $data['city'] = $refCity;
        }

        if (($data['lat'] ?? null) === null && ($data['lng'] ?? null) === null) {
            if (isset($geo['lat'], $geo['lng'])) {
                $data['lat'] = $geo['lat'];
                $data['lng'] = $geo['lng'];
            }
        }

        $payload = is_array($data['provider_payload'] ?? null) ? $data['provider_payload'] : [];
        $payload['yandex_geocode'] = [
            'queried_at' => now()->toIso8601String(),
            'precision' => $geo['precision'] ?? null,
            'formatted' => $geo['formatted'] ?? null,
        ];
        $data['provider_payload'] = $payload;

        if (($data['source'] ?? 'manual') === 'manual') {
            $data['source'] = 'yandex_geocode';
        }

        return $data;
    }

    /**
     * @return array{postal_code?: string, locality?: string, region?: string, lat?: string, lng?: string, precision?: string, formatted?: string}|null
     */
    private function geocodeRequest(string $apiKey, string $geocode): ?array
    {
        try {
            $res = Http::acceptJson()
                ->timeout(12)
                ->get(self::GEO_URL, [
                    'apikey' => $apiKey,
                    'geocode' => $geocode,
                    'format' => 'json',
                    'results' => 1,
                    'lang' => 'ru_RU',
                ]);
        } catch (\Throwable $e) {
            Log::debug('yandex_geocode:network', ['message' => $e->getMessage()]);

            return null;
        }

        if (! $res->successful()) {
            Log::debug('yandex_geocode:http', ['status' => $res->status()]);

            return null;
        }

        $json = $res->json();
        if (! is_array($json)) {
            return null;
        }

        $member = data_get($json, 'response.GeoObjectCollection.featureMember.0');
        if (! is_array($member)) {
            return null;
        }

        $meta = data_get($member, 'GeoObject.metaDataProperty.GeocoderMetaData');
        if (! is_array($meta)) {
            return null;
        }

        $address = $meta['Address'] ?? null;
        $postal = '';
        if (is_array($address)) {
            $postal = trim((string) ($address['postal_code'] ?? ''));
            if ($postal === '' && isset($address['Components']) && is_array($address['Components'])) {
                foreach ($address['Components'] as $c) {
                    if (! is_array($c)) {
                        continue;
                    }
                    if (($c['kind'] ?? '') === 'postal_code' && ($c['name'] ?? '') !== '') {
                        $postal = trim((string) $c['name']);
                        break;
                    }
                }
            }
        }

        $locality = '';
        $region = '';
        if (is_array($address) && isset($address['Components']) && is_array($address['Components'])) {
            foreach ($address['Components'] as $c) {
                if (! is_array($c)) {
                    continue;
                }
                $kind = (string) ($c['kind'] ?? '');
                $name = trim((string) ($c['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                if ($kind === 'locality') {
                    $locality = $name;
                }
                if ($kind === 'province' || $kind === 'area') {
                    $region = $name;
                }
            }
        }

        $pos = data_get($member, 'GeoObject.Point.pos');
        $lat = null;
        $lng = null;
        if (is_string($pos) && str_contains($pos, ' ')) {
            [$lngStr, $latStr] = explode(' ', $pos, 2);
            $lat = is_numeric($latStr) ? (string) $latStr : null;
            $lng = is_numeric($lngStr) ? (string) $lngStr : null;
        }

        $out = array_filter([
            'postal_code' => $postal !== '' ? $postal : null,
            'locality' => $locality !== '' ? $locality : null,
            'region' => $region !== '' ? $region : null,
            'lat' => $lat,
            'lng' => $lng,
            'precision' => isset($meta['precision']) ? (string) $meta['precision'] : null,
            'formatted' => isset($meta['text']) ? (string) $meta['text'] : null,
        ], fn ($v) => $v !== null && $v !== '');

        return $out === [] ? null : $out;
    }
}
