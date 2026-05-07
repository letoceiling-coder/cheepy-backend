<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailIntegration;
use App\Services\Marketing\MarketplaceMailDispatcher;
use App\Services\Marketing\TransactionalMarketingMail;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CrmMailIntegrationController extends Controller
{
    private const NAMES_ORDER = ['smtp', 'telegram', 'whatsapp', 'vk'];

    private const TITLE_MAP = [
        'smtp' => 'Электронная почта (SMTP)',
        'telegram' => 'Telegram Bot API',
        'whatsapp' => 'WhatsApp Cloud API (Meta)',
        'vk' => 'VK сообщество (API)',
    ];

    private const DOCS_MAP = [
        'smtp' => 'https://laravel.com/docs/mail',
        'telegram' => 'https://core.telegram.org/bots/api',
        'whatsapp' => 'https://developers.facebook.com/docs/whatsapp/cloud-api',
        'vk' => 'https://dev.vk.com/ru/api/community-messages',
    ];

    public function index(): JsonResponse
    {
        $names = self::NAMES_ORDER;
        $rows = MailIntegration::query()
            ->whereIn('name', $names)
            ->get()
            ->sortBy(fn ($r) => array_search($r->name, $names, true));

        $data = $rows->values()->map(fn (MailIntegration $r) => [
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

        return $this->jsonRow($row);
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

        $maskedKeys = $this->maskedConfigKeys();
        $config = $row->config ?? [];
        foreach ($data as $key => $value) {
            if ($key === 'is_active' || ! in_array($key, $allowed, true)) {
                continue;
            }
            if (in_array($key, $maskedKeys, true) && ($value === '***' || $value === '' || $value === null)) {
                continue;
            }
            $config[$key] = $value !== null && $value !== '' ? $value : null;
        }
        $row->update(['config' => $config]);
        $row->refresh();

        return $this->jsonRow($row);
    }

    public function test(Request $request, string $name): JsonResponse
    {
        $row = MailIntegration::query()->where('name', $name)->firstOrFail();

        if ($name === 'smtp') {
            return $this->testSmtp($request, $row);
        }

        return match ($name) {
            'telegram' => $this->testTelegram($request, $row),
            'whatsapp' => $this->testWhatsappPlaceholder(),
            'vk' => $this->testVkPlaceholder(),
            default => response()->json(['success' => false, 'message' => 'Неизвестная интеграция'], 422),
        };
    }

    private function jsonRow(MailIntegration $row): JsonResponse
    {
        $name = $row->name;
        $config = $row->config ?? [];

        return response()->json([
            'name' => $name,
            'title' => self::TITLE_MAP[$name] ?? $name,
            'is_active' => $row->is_active,
            'status' => $this->isConnected($row) ? 'connected' : 'disconnected',
            'config' => $this->maskConfig($config),
            'hints' => $this->hintsFor($name),
            'last_successful_send_at' => $row->last_successful_send_at?->toIso8601String(),
            'config_schema' => $this->configSchemaFor($name),
            'docs_url' => self::DOCS_MAP[$name] ?? null,
        ]);
    }

    private function testSmtp(Request $request, MailIntegration $row): JsonResponse
    {
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

    private function testTelegram(Request $request, MailIntegration $row): JsonResponse
    {
        $validated = $request->validate([
            'chat_id' => ['nullable', 'string', 'max:64'],
            'subject' => ['nullable', 'string', 'max:255'],
        ]);
        $token = trim((string) ($row->config['bot_token'] ?? ''));
        if ($token === '') {
            return response()->json(['success' => false, 'message' => 'Укажите bot_token'], 422);
        }
        try {
            $resp = Http::timeout(12)->get('https://api.telegram.org/bot'.$token.'/getMe');
            if (! $resp->successful() || ! ($resp->json('ok'))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Telegram отклонил токен: '.($resp->json('description') ?: $resp->body()),
                ], 422);
            }
            $chatId = trim((string) ($validated['chat_id'] ?? ($row->config['default_chat_id'] ?? '')));
            if ($chatId !== '') {
                $text = 'Тест маркетинга Cheepy CRM OK';
                $send = Http::timeout(15)->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);
                if (! $send->successful() || ! ($send->json('ok'))) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Сообщение не отправлено: '.($send->json('description') ?: $send->body()),
                    ], 422);
                }
                $row->forceFill(['last_successful_send_at' => now()])->save();

                return response()->json(['success' => true, 'message' => 'Токен валиден, тестовое сообщение отправлено']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Токен валиден. Укажите chat_id пользователя или default_chat_id (или передайте chat_id в тесте), чтобы отправить сообщение.',
            ]);
        } catch (ConnectionException $e) {
            return response()->json(['success' => false, 'message' => 'Сеть: '.$e->getMessage()], 422);
        }
    }

    private function testWhatsappPlaceholder(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Отправку WhatsApp из CRM подключайте после настройки access_token и phone_number_id — проверка в разработке (документируйте токены и используйте Graph API напрямую).',
        ], 422);
    }

    private function testVkPlaceholder(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Для VK укажите токен сообщества и group_id — автоматический тест чата добавим отдельно (документируйте ключ в полях конфигурации).',
        ], 422);
    }

    private function isConnected(MailIntegration $r): bool
    {
        $c = $r->config ?? [];

        return match ($r->name) {
            'smtp' => $r->is_active && ! empty(trim((string) ($c['host'] ?? '')))
                && ! empty(trim((string) ($c['username'] ?? '')))
                && ! empty(trim((string) ($c['password'] ?? '')))
                && ! empty(trim((string) ($c['from_email'] ?? ''))),
            'telegram' => ! empty(trim((string) ($c['bot_token'] ?? ''))),
            'whatsapp' => ! empty(trim((string) ($c['phone_number_id'] ?? ''))) && ! empty(trim((string) ($c['access_token'] ?? ''))),
            'vk' => ! empty(trim((string) ($c['group_access_token'] ?? ''))) && ! empty(trim((string) ($c['group_id'] ?? ''))),
            default => false,
        };
    }

    /** @return array<string, mixed> */
    private function hintsFor(string $name): array
    {
        return match ($name) {
            'smtp' => [
                'default_port_tls' => 587,
                'default_port_ssl' => 465,
            ],
            'telegram' => [
                'get_token' => 'Создайте бота через @BotFather.',
            ],
            'whatsapp' => [
                'note' => 'Нужны phone_number_id и постоянный access token из Meta Developers.',
            ],
            'vk' => [
                'note' => 'Ключ доступа сообщества из «Управление → Работа с API».',
            ],
            default => [],
        };
    }

    /** @return list<string> */
    private function maskedConfigKeys(): array
    {
        return ['password', 'bot_token', 'access_token', 'group_access_token'];
    }

    private function maskConfig(array $config): array
    {
        $masked = array_flip($this->maskedConfigKeys());
        $out = [];
        foreach ($config as $k => $v) {
            if (isset($masked[$k]) && is_string($v) && strlen($v) > 0) {
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
            'telegram' => [
                ['key' => 'bot_token', 'label' => 'Токен бота (@BotFather)', 'type' => 'password', 'required' => true],
                ['key' => 'default_chat_id', 'label' => 'Chat ID получателя для теста (необязательно)', 'type' => 'text'],
            ],
            'whatsapp' => [
                ['key' => 'phone_number_id', 'label' => 'Phone number ID (WhatsApp)', 'type' => 'text', 'required' => true],
                ['key' => 'access_token', 'label' => 'Access token Cloud API', 'type' => 'password', 'required' => true],
                ['key' => 'business_account_id', 'label' => 'Business Account ID WABA (необяз.)', 'type' => 'text'],
                ['key' => 'webhook_verify_token', 'label' => 'Verify token для вебхука (необяз.)', 'type' => 'text'],
            ],
            'vk' => [
                ['key' => 'group_access_token', 'label' => 'Токен доступа сообщества', 'type' => 'password', 'required' => true],
                ['key' => 'group_id', 'label' => 'ID группы (положительный числовой)', 'type' => 'text', 'required' => true],
                ['key' => 'api_version', 'label' => 'Версия API (напр. 5.199)', 'type' => 'text'],
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
