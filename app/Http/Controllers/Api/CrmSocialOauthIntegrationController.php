<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialOauthIntegration;
use App\Services\Auth\SocialOAuthFlowService;
use App\Support\SocialOauthCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmSocialOauthIntegrationController extends Controller
{
    private const TITLE_MAP = [
        'vk' => 'ВКонтакте',
        'yandex' => 'Яндекс',
        'ok' => 'Одноклассники',
    ];

    public function __construct(
        private SocialOAuthFlowService $flow
    ) {}

    public function index(): JsonResponse
    {
        $rows = SocialOauthIntegration::query()
            ->whereIn('name', SocialOauthCatalog::PROVIDERS)
            ->orderByRaw("FIELD(name, 'vk', 'yandex', 'ok')")
            ->get();

        $data = $rows->map(fn (SocialOauthIntegration $r) => [
            'name' => $r->name,
            'title' => self::TITLE_MAP[$r->name] ?? $r->name,
            'is_active' => $r->is_active,
            'status' => $this->flow->isConfigured($r->name, $r) ? 'connected' : 'disconnected',
            'last_successful_oauth_at' => $r->last_successful_oauth_at?->toIso8601String(),
        ]);

        return response()->json($data);
    }

    public function show(string $name): JsonResponse
    {
        if (! in_array($name, SocialOauthCatalog::PROVIDERS, true)) {
            abort(404);
        }

        $row = SocialOauthIntegration::where('name', $name)->firstOrFail();
        $config = $row->config ?? [];

        return response()->json([
            'name' => $row->name,
            'title' => self::TITLE_MAP[$row->name] ?? $name,
            'is_active' => $row->is_active,
            'status' => $this->flow->isConfigured($name, $row) ? 'connected' : 'disconnected',
            'config' => $this->maskConfig($name, $config),
            'hints' => [
                'oauth_callback_url' => $this->flow->defaultBackendCallbackUrl($name),
                'oauth_redirect_uri_for_provider_console' => $this->flow->redirectUriForIntegration($name, $row),
                'frontend_return_example' => $this->frontendBase().'/auth',
            ],
            'documentation' => SocialOauthCatalog::documentation($name),
            'last_successful_oauth_at' => $row->last_successful_oauth_at?->toIso8601String(),
            'config_schema' => $this->configSchemaFor($name),
        ]);
    }

    public function update(Request $request, string $name): JsonResponse
    {
        if (! in_array($name, SocialOauthCatalog::PROVIDERS, true)) {
            abort(404);
        }

        $row = SocialOauthIntegration::where('name', $name)->firstOrFail();
        $allowed = $this->configFieldsFor($name);
        $rules = collect($allowed)->mapWithKeys(fn ($k) => [$k => 'nullable|string'])->all();
        $rules['is_active'] = 'nullable|boolean';
        $data = $request->validate($rules);

        if (array_key_exists('is_active', $data)) {
            $row->update(['is_active' => (bool) $data['is_active']]);
        }

        $sensitiveMask = ['client_secret', 'secret_key', 'service_token'];
        $config = $row->config ?? [];
        foreach ($data as $key => $value) {
            if ($key === 'is_active' || ! in_array($key, $allowed, true)) {
                continue;
            }
            if (in_array($key, $sensitiveMask, true) && ($value === '***' || $value === '' || $value === null)) {
                continue;
            }
            $config[$key] = $value !== null && $value !== '' ? $value : null;
        }
        $row->update(['config' => $config]);
        $row->refresh();

        return $this->show($name);
    }

    private function frontendBase(): string
    {
        $u = rtrim((string) config('services.social_oauth.frontend_base_url'), '/');

        return $u !== '' ? $u : rtrim((string) config('app.url'), '/');
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function maskConfig(string $name, array $config): array
    {
        $keys = match ($name) {
            'vk' => ['client_secret', 'service_token'],
            'yandex' => ['client_secret'],
            'ok' => ['secret_key'],
            default => [],
        };

        $out = [];
        foreach ($config as $k => $v) {
            if (in_array($k, $keys, true) && is_string($v) && strlen($v) > 0) {
                $out[$k] = '***';
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private function configSchemaFor(string $name): array
    {
        $commonOptional = [
            [
                'key' => 'redirect_uri_override',
                'label' => 'Свой Redirect URI (редко; должен совпадать с указанным у провайдера)',
                'type' => 'text',
            ],
            [
                'key' => 'scope_override',
                'label' => 'Переопределение scope (через пробел для Яндекса; для VK через запятую или как в документации)',
                'type' => 'textarea',
            ],
        ];

        return match ($name) {
            'vk' => array_merge([
                ['key' => 'client_id', 'label' => 'ID приложения (client_id)', 'type' => 'text', 'required' => true],
                ['key' => 'client_secret', 'label' => 'Защищённый ключ (client_secret)', 'type' => 'password', 'required' => true],
                ['key' => 'service_token', 'label' => 'Сервисный ключ доступа (опционально)', 'type' => 'password'],
            ], $commonOptional),
            'yandex' => array_merge([
                ['key' => 'client_id', 'label' => 'Идентификатор приложения (Client ID)', 'type' => 'text', 'required' => true],
                ['key' => 'client_secret', 'label' => 'Секрет приложения (Client secret)', 'type' => 'password', 'required' => true],
            ], $commonOptional),
            'ok' => array_merge([
                ['key' => 'application_id', 'label' => 'Application ID', 'type' => 'text', 'required' => true],
                ['key' => 'public_key', 'label' => 'Публичный ключ приложения', 'type' => 'text', 'required' => true],
                ['key' => 'secret_key', 'label' => 'Секретный ключ приложения', 'type' => 'password', 'required' => true],
            ], $commonOptional),
            default => [],
        };
    }

    /**
     * @return string[]
     */
    private function configFieldsFor(string $name): array
    {
        return array_map(fn ($f) => $f['key'], $this->configSchemaFor($name));
    }
}
