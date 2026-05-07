<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailIntegration;
use App\Services\Marketing\MarketplaceMailDispatcher;
use App\Services\Marketing\TransactionalMarketingMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmMailIntegrationController extends Controller
{
    private const TITLE_MAP = [
        'smtp' => 'Электронная почта (SMTP)',
    ];

    private const DOCS_MAP = [
        'smtp' => 'https://laravel.com/docs/mail',
    ];

    public function index(): JsonResponse
    {
        $rows = MailIntegration::query()
            ->whereIn('name', ['smtp'])
            ->orderBy('name')
            ->get();

        $data = $rows->map(fn (MailIntegration $r) => [
            'name' => $r->name,
            'title' => self::TITLE_MAP[$r->name] ?? $r->name,
            'is_active' => $r->is_active,
            'status' => $this->isConnected($r) ? 'connected' : 'disconnected',
            'last_successful_send_at' => $r->last_successful_send_at?->toIso8601String(),
        ]);

        return response()->json($data);
    }

    public function show(string $name): JsonResponse
    {
        $row = MailIntegration::query()->where('name', $name)->firstOrFail();
        $config = $row->config ?? [];

        return response()->json([
            'name' => $row->name,
            'title' => self::TITLE_MAP[$row->name] ?? $row->name,
            'is_active' => $row->is_active,
            'status' => $this->isConnected($row) ? 'connected' : 'disconnected',
            'config' => $this->maskConfig($config),
            'hints' => $this->hintsFor($name),
            'last_successful_send_at' => $row->last_successful_send_at?->toIso8601String(),
            'config_schema' => $this->configSchemaFor($name),
            'docs_url' => self::DOCS_MAP[$name] ?? null,
        ]);
    }

    public function update(Request $request, string $name): JsonResponse
    {
        $row = MailIntegration::query()->where('name', $name)->firstOrFail();
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
            'last_successful_send_at' => $row->last_successful_send_at?->toIso8601String(),
            'config_schema' => $this->configSchemaFor($name),
            'docs_url' => self::DOCS_MAP[$name] ?? null,
        ]);
    }

    public function test(Request $request, string $name): JsonResponse
    {
        if ($name !== 'smtp') {
            return response()->json(['success' => false, 'message' => 'Тест для этого провайдера не реализован'], 422);
        }

        /** @var MailIntegration $row */
        $row = MailIntegration::query()->where('name', $name)->firstOrFail();
        if (! $row->is_active) {
            return response()->json(['success' => false, 'message' => 'Включите SMTP в карточке интеграции'], 422);
        }

        $validated = $request->validate([
            'to' => ['required', 'email', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
        ]);

        $mailer = app(TransactionalMarketingMail::class);
        $vars = $mailer->previewVars();
        $subject = trim((string) ($validated['subject'] ?? '')) !== ''
            ? $mailer->merge($validated['subject'], $vars)
            : '['.$vars['marketplace_name'].'] Тест SMTP из CRM';

        $html = $mailer->merge(
            '<div style="font-family:Arial,sans-serif;padding:16px;background:#fafafa">'
                .'<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px">'
                .'<tr><td>'
                .$vars['logo_block']
                .'<p>Это проверочное письмо из CRM маркетплейса <strong>{{marketplace_name}}</strong>.</p>'
                .'<p>Если вы видите это письмо, SMTP настроен корректно.</p>'
                .'<hr style="border:none;border-top:1px solid #eee;margin:16px 0"/>'
                .'<small style="color:#666">{{site_url}}</small>'
                .'</td></tr></table></div>',
            $vars
        );

        $ok = MarketplaceMailDispatcher::sendHtml((string) $validated['to'], $subject, $html);

        return response()->json([
            'success' => $ok,
            'message' => $ok ? 'Тестовое письмо отправлено' : 'Не удалось отправить (проверьте логи и реквизиты SMTP)',
        ]);
    }

    private function isConnected(MailIntegration $r): bool
    {
        if ($r->name !== 'smtp') {
            return false;
        }

        return ! empty(trim((string) ($r->config['host'] ?? '')))
            && ! empty(trim((string) ($r->config['username'] ?? '')))
            && ! empty(trim((string) ($r->config['password'] ?? '')))
            && ! empty(trim((string) ($r->config['from_email'] ?? '')));
    }

    /**
     * @return array<string, int>
     */
    private function hintsFor(string $name): array
    {
        return match ($name) {
            'smtp' => [
                'default_port_tls' => 587,
                'default_port_ssl' => 465,
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
            'smtp' => [
                ['key' => 'host', 'label' => 'SMTP-хост', 'type' => 'text', 'required' => true],
                ['key' => 'port', 'label' => 'Порт (обычно 587 или 465)', 'type' => 'text'],
                ['key' => 'encryption', 'label' => 'Шифрование: tls | ssl | none', 'type' => 'text'],
                ['key' => 'username', 'label' => 'Логин', 'type' => 'text', 'required' => true],
                ['key' => 'password', 'label' => 'Пароль', 'type' => 'password', 'required' => true],
                ['key' => 'from_email', 'label' => 'Адрес «От» (From)', 'type' => 'text', 'required' => true],
                ['key' => 'from_name', 'label' => 'Имя отправителя', 'type' => 'text'],
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
