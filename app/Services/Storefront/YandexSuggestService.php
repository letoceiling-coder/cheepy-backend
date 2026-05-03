<?php

namespace App\Services\Storefront;

use App\Models\DeliveryIntegration;
use Illuminate\Support\Facades\Http;

class YandexSuggestService
{
    /**
     * @return array{enabled: bool, data: array<int, array<string, mixed>>, message?: string}
     */
    public function suggest(string $text): array
    {
        $row = DeliveryIntegration::query()->where('name', 'yandex_maps')->first();
        $config = $row?->config ?? [];
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if (! $row?->is_active || $apiKey === '') {
            return ['enabled' => false, 'data' => [], 'message' => 'Интеграция Яндекс Карт не активна'];
        }

        try {
            $res = Http::acceptJson()
                ->timeout(12)
                ->get('https://suggest-maps.yandex.ru/v1/suggest', [
                    'apikey' => $apiKey,
                    'text' => $text,
                    'print_address' => 1,
                    'types' => 'geo,house',
                    'lang' => 'ru_RU',
                    'results' => 10,
                ]);
        } catch (\Throwable $e) {
            return ['enabled' => true, 'data' => [], 'message' => $e->getMessage()];
        }

        if (! $res->successful()) {
            return ['enabled' => true, 'data' => [], 'message' => 'Яндекс Карт не вернул подсказки'];
        }

        $body = $res->json();
        $items = is_array($body) && is_array($body['results'] ?? null) ? $body['results'] : [];

        return [
            'enabled' => true,
            'data' => array_map(function (array $item) {
                $title = is_array($item['title'] ?? null) ? (string) ($item['title']['text'] ?? '') : '';
                $subtitle = is_array($item['subtitle'] ?? null) ? (string) ($item['subtitle']['text'] ?? '') : '';

                return [
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'address' => trim($subtitle !== '' ? $subtitle.', '.$title : $title, ' ,'),
                    'uri' => (string) ($item['uri'] ?? ''),
                    'raw' => $item,
                ];
            }, $items),
        ];
    }
}
