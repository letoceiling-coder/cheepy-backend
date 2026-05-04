<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryIntegration;
use App\Services\Delivery\CdekOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CrmDeliveryIntegrationController extends Controller
{
    private const TITLE_MAP = [
        'cdek' => 'СДЭК',
        'yandex_maps' => 'Яндекс Карты',
        'nova_poshta' => 'Новая Почта',
        'dhl' => 'DHL',
        'russian_post' => 'Почта России',
    ];

    public function index(): JsonResponse
    {
        $rows = DeliveryIntegration::query()
            ->whereIn('name', ['cdek', 'yandex_maps', 'nova_poshta', 'dhl', 'russian_post'])
            ->orderByRaw("FIELD(name, 'cdek', 'yandex_maps', 'nova_poshta', 'dhl', 'russian_post')")
            ->get();

        $data = $rows->map(fn (DeliveryIntegration $r) => [
            'name' => $r->name,
            'title' => self::TITLE_MAP[$r->name] ?? $r->name,
            'is_active' => $r->is_active,
            'status' => $this->isConnected($r) ? 'connected' : 'disconnected',
            'last_successful_auth_at' => $r->last_successful_auth_at?->toIso8601String(),
        ]);

        return response()->json($data);
    }

    public function show(string $name): JsonResponse
    {
        $row = DeliveryIntegration::where('name', $name)->firstOrFail();
        $config = $row->config ?? [];

        return response()->json([
            'name' => $row->name,
            'title' => self::TITLE_MAP[$row->name] ?? $row->name,
            'is_active' => $row->is_active,
            'status' => $this->isConnected($row) ? 'connected' : 'disconnected',
            'config' => $this->maskConfig($config),
            'hints' => $this->hintsFor($row->name, $config),
            'last_successful_auth_at' => $row->last_successful_auth_at?->toIso8601String(),
            'config_schema' => $this->configSchemaFor($row->name),
            'docs_url' => $this->docsUrlFor($row->name),
        ]);
    }

    public function update(Request $request, string $name): JsonResponse
    {
        $row = DeliveryIntegration::where('name', $name)->firstOrFail();
        $allowed = $this->configFieldsFor($name);
        $rules = collect($allowed)->mapWithKeys(fn ($k) => [$k => 'nullable|string'])->all();
        $rules['is_active'] = 'nullable|boolean';
        $data = $request->validate($rules);

        if (array_key_exists('is_active', $data)) {
            $row->update(['is_active' => (bool) $data['is_active']]);
        }

        $sensitive = ['client_secret', 'api_key', 'access_token', 'auth_password'];
        $config = $row->config ?? [];
        foreach ($data as $key => $value) {
            if ($key === 'is_active' || ! in_array($key, $allowed, true)) {
                continue;
            }
            if (in_array($key, $sensitive, true) && ($value === '***' || $value === '' || $value === null)) {
                continue;
            }
            $config[$key] = $value !== null && $value !== '' ? $value : null;
        }
        $row->update(['config' => $config]);

        $row->refresh();

        return response()->json([
            'name' => $row->name,
            'title' => self::TITLE_MAP[$row->name] ?? $row->name,
            'is_active' => $row->is_active,
            'status' => $this->isConnected($row) ? 'connected' : 'disconnected',
            'config' => $this->maskConfig($row->config ?? []),
            'hints' => $this->hintsFor($row->name, $row->config ?? []),
            'last_successful_auth_at' => $row->last_successful_auth_at?->toIso8601String(),
            'config_schema' => $this->configSchemaFor($row->name),
            'docs_url' => $this->docsUrlFor($row->name),
        ]);
    }

    public function test(string $name): JsonResponse
    {
        $row = DeliveryIntegration::where('name', $name)->firstOrFail();

        if ($name === 'yandex_maps') {
            $config = $row->config ?? [];
            $key = trim((string) ($config['api_key'] ?? ''));

            if (! $row->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Включите интеграцию переключателем ниже.',
                ]);
            }

            if ($key === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Укажите API‑ключ (HTTP API Яндекс.Карт, см. документацию Suggest).',
                ]);
            }

            try {
                $response = Http::timeout(15)
                    ->acceptJson()
                    ->get('https://suggest-maps.yandex.ru/v1/suggest', [
                        'apikey' => $key,
                        'text' => 'Москва, Тверская',
                        'print_address' => 1,
                        'results' => 2,
                        'lang' => 'ru_RU',
                    ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Сеть: '.$e->getMessage(),
                ]);
            }

            if (! $response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Suggest HTTP '.$response->status().'. Проверьте ключ и доступ к Suggest API в кабинете разработчика.',
                ]);
            }

            $body = $response->json();
            $results = is_array($body) ? ($body['results'] ?? null) : null;
            if (! is_array($results)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неожиданный формат ответа Suggest API (нет results).',
                ]);
            }

            $row->update(['last_successful_auth_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'Suggest API: OK ('.count($results).' подсказок). Этот же ключ должен быть разрешён для Geocoder (см. поле ниже и документацию Geocoder API).',
            ]);
        }

        if ($name === 'russian_post') {
            $config = $row->config ?? [];

            return response()->json([
                'success' => $row->is_active && ! empty(trim((string) ($config['access_token'] ?? ''))),
                'message' => $row->is_active && ! empty(trim((string) ($config['access_token'] ?? '')))
                    ? 'Токен указан — можно запрашивать тариф'
                    : 'Укажите токен в ЛК отправки Почты России и включите интеграцию',
            ]);
        }

        if ($name !== 'cdek') {
            return response()->json([
                'success' => false,
                'message' => 'Проверка для этой службы пока не реализована',
            ]);
        }

        $config = $row->config ?? [];
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $secret = trim((string) ($config['client_secret'] ?? ''));
        $env = ($config['environment'] ?? CdekOAuthService::ENV_PRODUCTION) === CdekOAuthService::ENV_INTEGRATION
            ? CdekOAuthService::ENV_INTEGRATION
            : CdekOAuthService::ENV_PRODUCTION;

        $cdek = app(CdekOAuthService::class);
        $result = $cdek->requestToken($clientId, $secret, $env);

        if ($result['success']) {
            $row->update(['last_successful_auth_at' => now()]);
            if (! $row->is_active) {
                $result['message'] .= ' Внимание: интеграция выключена — расчёт доставки на витрине не выполняется. Включите интеграцию (флаг активности) и сохраните.';
            }
        }

        return response()->json($result);
    }

    private function isConnected(DeliveryIntegration $r): bool
    {
        $c = $r->config ?? [];

        return match ($r->name) {
            'cdek' => ! empty($c['client_id']) && ! empty($c['client_secret']),
            'yandex_maps' => ! empty($c['api_key']),
            'russian_post' => ! empty(trim((string) ($c['access_token'] ?? ''))),
            default => false,
        };
    }

    private function maskConfig(array $config): array
    {
        $out = [];
        foreach ($config as $k => $v) {
            if (in_array($k, ['client_secret', 'api_key', 'access_token', 'auth_password'], true) && is_string($v) && strlen($v) > 0) {
                $out[$k] = '***';
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * Подсказки только для отображения (не persist в config).
     *
     * @return array<string, string>
     */
    private function hintsFor(string $name, array $config): array
    {
        if ($name === 'yandex_maps') {
            return [
                'suggest_url' => 'https://suggest-maps.yandex.ru/v1/suggest',
                'geocoder_url' => 'https://geocode-maps.yandex.ru/1.x',
                'postal_note' => 'Подстановка почтовых индексов: сервер дергает Geocoder HTTPS (тот же API‑ключ должен быть разрешён в кабинете).',
                'developer_console' => 'https://developer.tech.yandex.ru/',
            ];
        }

        if ($name === 'russian_post') {
            return [
                'tariff_endpoint' => 'POST https://otpravka-api.pochta.ru/1.0/tariff',
                'note' => 'Для курьерских режимов задайте mail_type из спецификации отправки.',
            ];
        }

        if ($name !== 'cdek') {
            return [];
        }

        $env = ($config['environment'] ?? CdekOAuthService::ENV_PRODUCTION) === CdekOAuthService::ENV_INTEGRATION
            ? CdekOAuthService::ENV_INTEGRATION
            : CdekOAuthService::ENV_PRODUCTION;

        $cdek = app(CdekOAuthService::class);
        $base = $cdek->apiBase($env);
        $appUrl = rtrim((string) config('app.url'), '/');

        return [
            'oauth_token_url' => $cdek->oauthTokenUrl($env),
            'api_base_url' => $base,
            'webhook_order_status_url' => $appUrl !== '' ? $appUrl.'/api/v1/webhooks/cdek/orders' : '',
        ];
    }

    private function docsUrlFor(string $name): ?string
    {
        return match ($name) {
            'cdek' => 'https://apidoc.cdek.ru/',
            'yandex_maps' => 'https://yandex.ru/maps-api/docs/suggest-api/',
            'russian_post' => 'https://otpravka.pochta.ru/specification',
            default => null,
        };
    }

    private function configSchemaFor(string $name): array
    {
        return match ($name) {
            'cdek' => [
                [
                    'key' => 'environment',
                    'label' => 'Контур API (официально: боевой / интеграционный)',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        ['value' => CdekOAuthService::ENV_PRODUCTION, 'label' => 'Боевой (api.cdek.ru)'],
                        ['value' => CdekOAuthService::ENV_INTEGRATION, 'label' => 'Интеграционный (api.edu.cdek.ru)'],
                    ],
                ],
                ['key' => 'client_id', 'label' => 'Идентификатор аккаунта (Account)', 'type' => 'text', 'required' => true],
                ['key' => 'client_secret', 'label' => 'Секретный ключ (Secure password)', 'type' => 'password', 'required' => true],
                ['key' => 'oauth_token_url', 'label' => 'Точка получения токена (OAuth 2.0, только чтение)', 'type' => 'text', 'readonly' => true],
                ['key' => 'api_base_url', 'label' => 'Базовый URL API (только чтение)', 'type' => 'text', 'readonly' => true],
                ['key' => 'webhook_order_status_url', 'label' => 'URL вебхука статусов заказа (укажите в ЛК СДЭК, если поддерживается)', 'type' => 'text', 'readonly' => true],
                ['key' => 'sender_company', 'label' => 'Отправитель: название компании', 'type' => 'text'],
                ['key' => 'sender_name', 'label' => 'Отправитель: контактное лицо', 'type' => 'text'],
                ['key' => 'sender_phone', 'label' => 'Отправитель: телефон', 'type' => 'text'],
                ['key' => 'sender_email', 'label' => 'Отправитель: email', 'type' => 'text'],
                ['key' => 'sender_city_code', 'label' => 'Отправитель: код населённого пункта СДЭК (integer из справочника)', 'type' => 'text'],
                ['key' => 'sender_postal_code', 'label' => 'Отправитель: почтовый индекс', 'type' => 'text'],
                ['key' => 'sender_address', 'label' => 'Отправитель: адрес (улица, дом, офис)', 'type' => 'textarea'],
                ['key' => 'default_tariff_code', 'label' => 'Тариф по умолчанию (код тарифа СДЭК для типовых отправлений)', 'type' => 'text'],
                ['key' => 'default_shipment_point', 'label' => 'Код пункта приёма / ПВЗ отправителя (если отгрузка с ПВЗ)', 'type' => 'text'],
                ['key' => 'additional_order_types', 'label' => 'Доп. тип заказа / пометки для ЛК (необязательно, текст)', 'type' => 'textarea'],
            ],
            'yandex_maps' => [
                [
                    'key' => 'api_key',
                    'label' => 'API‑ключ (HTTP API: JavaScript API и HTTP Геокодер + доступ к Suggest)',
                    'type' => 'password',
                    'required' => true,
                ],
                [
                    'key' => 'suggest_url',
                    'label' => 'Suggest API — официальный endpoint (только чтение)',
                    'type' => 'text',
                    'readonly' => true,
                ],
                [
                    'key' => 'geocoder_url',
                    'label' => 'Geocoder API — base URL HTTPS (только чтение)',
                    'type' => 'text',
                    'readonly' => true,
                ],
                [
                    'key' => 'developer_console',
                    'label' => 'Регистрация ключа (кабинет разработчика, только чтение)',
                    'type' => 'text',
                    'readonly' => true,
                ],
            ],
            'nova_poshta' => [],
            'dhl' => [],
            'russian_post' => [
                ['key' => 'sender_postal_index', 'label' => 'Индекс места отправления (6 цифр)', 'type' => 'text'],
                ['key' => 'access_token', 'label' => 'Токен (Authorization: AccessToken …)', 'type' => 'password', 'required' => true],
                ['key' => 'auth_login', 'label' => 'Логин для X-User-Authorization (опционально)', 'type' => 'text'],
                ['key' => 'auth_password', 'label' => 'Пароль для X-User-Authorization', 'type' => 'password'],
                ['key' => 'mail_type', 'label' => 'Вид РПО (mail-type), например POSTAL_PARCEL или из спецификации', 'type' => 'text', 'required' => true],
                ['key' => 'mail_category', 'label' => 'Категория отправления (mail-category)', 'type' => 'text', 'required' => true],
                ['key' => 'payment_method', 'label' => 'Способ расчёта (payment-method), например CASHLESS', 'type' => 'text', 'required' => true],
            ],
            default => [],
        };
    }

    /**
     * @return string[]
     */
    private function configFieldsFor(string $name): array
    {
        $schema = $this->configSchemaFor($name);

        return array_values(array_filter(
            array_map(fn ($f) => ($f['readonly'] ?? false) ? null : $f['key'], $schema)
        ));
    }
}
