<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsIntegration;
use App\Services\Sms\IqsmsSmsGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmSmsIntegrationController extends Controller
{
    private const TITLE_MAP = [
        'iqsms' => 'IQSMS (SMS Дисконт)',
    ];

    private const DOCS_MAP = [
        'iqsms' => 'https://iqsms.ru/api/api_about/',
    ];

    public function index(): JsonResponse
    {
        $rows = SmsIntegration::query()
            ->whereIn('name', ['iqsms'])
            ->orderByRaw("FIELD(name, 'iqsms')")
            ->get();

        $data = $rows->map(fn (SmsIntegration $r) => [
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
        $row = SmsIntegration::where('name', $name)->firstOrFail();
        $config = $row->config ?? [];

        return response()->json([
            'name' => $row->name,
            'title' => self::TITLE_MAP[$row->name] ?? $row->name,
            'is_active' => $row->is_active,
            'status' => $this->isConnected($row) ? 'connected' : 'disconnected',
            'config' => $this->maskConfig($config),
            'hints' => $this->hintsFor($name),
            'last_successful_auth_at' => $row->last_successful_auth_at?->toIso8601String(),
            'config_schema' => $this->configSchemaFor($name),
            'docs_url' => self::DOCS_MAP[$name] ?? null,
        ]);
    }

    public function update(Request $request, string $name): JsonResponse
    {
        $row = SmsIntegration::where('name', $name)->firstOrFail();
        $allowed = $this->configFieldsFor($name);
        $rules = collect($allowed)->mapWithKeys(fn ($k) => [$k => 'nullable|string'])->all();
        $rules['is_active'] = 'nullable|boolean';
        $data = $request->validate($rules);

        if (array_key_exists('is_active', $data)) {
            $row->update(['is_active' => (bool) $data['is_active']]);
        }

        $config = $row->config ?? [];
        foreach ($data as $key => $value) {
            if ($key === 'is_active' || ! in_array($key, $allowed, true)) {
                continue;
            }
            if ($key === 'password' && ($value === '***' || $value === '' || $value === null)) {
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
            'hints' => $this->hintsFor($name),
            'last_successful_auth_at' => $row->last_successful_auth_at?->toIso8601String(),
            'config_schema' => $this->configSchemaFor($name),
            'docs_url' => self::DOCS_MAP[$name] ?? null,
        ]);
    }

    public function test(Request $request, string $name): JsonResponse
    {
        $row = SmsIntegration::where('name', $name)->firstOrFail();

        if ($name !== 'iqsms') {
            return response()->json([
                'success' => false,
                'message' => 'Тест для этого провайдера не реализован',
            ]);
        }

        $validated = $request->validate([
            'mode' => 'nullable|string|in:balance,send',
            'test_phone' => 'nullable|string|max:32',
            'test_message' => 'nullable|string|max:500',
        ]);

        $mode = $validated['mode'] ?? 'balance';

        $config = $row->config ?? [];
        $login = trim((string) ($config['login'] ?? ''));
        $password = trim((string) ($config['password'] ?? ''));
        $sender = trim((string) ($config['sender'] ?? ''));
        $base = trim((string) ($config['api_base'] ?? ''));
        $baseUrl = $base !== '' ? $base : null;

        if ($login === '' || $password === '') {
            return response()->json([
                'success' => false,
                'message' => 'Укажите логин и пароль API в настройках.',
            ]);
        }

        $gw = new IqsmsSmsGateway;

        if ($mode === 'balance') {
            $result = $gw->balance($login, $password, $baseUrl);
            if ($result['success']) {
                $row->update(['last_successful_auth_at' => now()]);
            }

            return response()->json($result);
        }

        $phoneRaw = trim((string) ($validated['test_phone'] ?? ''));
        $phone = IqsmsSmsGateway::normalizePhoneRu($phoneRaw);
        if ($phone === null) {
            return response()->json([
                'success' => false,
                'message' => 'Укажите корректный номер РФ (например +79161234567 или 89161234567).',
            ]);
        }

        $msg = trim((string) ($validated['test_message'] ?? ''));
        if ($msg === '') {
            $msg = 'Cheepy CRM: тест SMS';
        }

        $send = $gw->send($login, $password, $phone, $msg, $sender !== '' ? $sender : null, $baseUrl);
        if ($send['success']) {
            $row->update(['last_successful_auth_at' => now()]);
        }

        return response()->json($send);
    }

    private function isConnected(SmsIntegration $r): bool
    {
        $c = $r->config ?? [];

        return match ($r->name) {
            'iqsms' => ! empty(trim((string) ($c['login'] ?? ''))) && ! empty(trim((string) ($c['password'] ?? ''))),
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    private function hintsFor(string $name): array
    {
        return match ($name) {
            'iqsms' => [
                'send_endpoint' => IqsmsSmsGateway::DEFAULT_BASE.'/messages/v2/send/',
                'balance_endpoint' => IqsmsSmsGateway::DEFAULT_BASE.'/messages/v2/balance/',
                'rest_docs' => 'https://iqsms.ru/api/api_rest/',
            ],
            default => [],
        };
    }

    private function maskConfig(array $config): array
    {
        $out = [];
        foreach ($config as $k => $v) {
            if ($k === 'password' && is_string($v) && strlen($v) > 0) {
                $out[$k] = '***';
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private function configSchemaFor(string $name): array
    {
        return match ($name) {
            'iqsms' => [
                ['key' => 'login', 'label' => 'Логин API', 'type' => 'text', 'required' => true],
                ['key' => 'password', 'label' => 'Пароль API', 'type' => 'password', 'required' => true],
                ['key' => 'sender', 'label' => 'Подпись отправителя (sender), как в ЛК', 'type' => 'text'],
                [
                    'key' => 'api_base',
                    'label' => 'Базовый URL API (редко нужно менять)',
                    'type' => 'text',
                ],
                ['key' => 'send_endpoint', 'label' => 'Отправка (GET)', 'type' => 'text', 'readonly' => true],
                ['key' => 'balance_endpoint', 'label' => 'Баланс (GET)', 'type' => 'text', 'readonly' => true],
                ['key' => 'rest_docs', 'label' => 'REST документация', 'type' => 'text', 'readonly' => true],
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
