<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailIntegration;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CrmMarketingChannelController extends Controller
{
    /** @return array<string, string> */
    private function docEmail(): array
    {
        return [
            'documentation_title' => 'Как подключить Email',
            'documentation_markdown' => <<<'MD'
### Настройка email-рассылок

1. Откройте **Интеграции → SMTP** и укажите реквизиты почтового сервера (Mail.ru, Yandex 360, Gmail SMTP, корпоративный Exchange и т.д.).
2. Поля **От (From)** должны совпадать с доменом, для которого выпущены записи SPF/DKIM у провайдера — иначе письма попадут в спам.
3. После сохранения используйте кнопку **«Тестовое письмо»**, чтобы убедиться, что сервер принимает авторизацию (логин и пароль приложения, не обычный пароль веб-интерфейса там, где нужен app password).
4. Аудиторию «подписчиков» считаем по пользователям с **согласием на маркетинг** в личном кабинете (`marketing_opt_in`).
5. Автоматические письма («регистрация», «заказ создан») уходят только при **включённом** SMTP и активных шаблонах в разделе «Шаблоны».
MD,
        ];
    }

    /** @return array<string, string> */
    private function docTelegram(): array
    {
        return [
            'documentation_title' => 'Как подключить Telegram',
            'documentation_markdown' => <<<'MD'
### Подготовка Telegram-бота

1. Создайте бота через [@BotFather](https://t.me/BotFather), получите токен API.
2. Включите режим **Privacy** по необходимости (для рассылки в личные чаты нужны явные подписки пользователей через `/start`).
3. Настройте вебхук на отдельный endpoint (в разработке). Пока счётчик подписчиков в CRM не ведётся — отображается **0** до появления синхронизации в БД.
4. Рекомендуем выделить отдельный бот для транзакций (заказы) и отдельный для промо.
MD,
        ];
    }

    /** @return array<string, string> */
    private function docWhatsapp(): array
    {
        return [
            'documentation_title' => 'Как подключить WhatsApp Business',
            'documentation_markdown' => <<<'MD'
### WhatsApp Cloud API / Business

1. Зарегистрируйте приложение в **Meta for Developers** и подключите номер к WhatsApp Business Platform.
2. Получите постоянный токен и ID телефона отправителя — интеграция в CRM находится в дорожной карте.
3. До внедрения API в продукт статус канала остаётся **не подключён**, подписчиков **0**.
MD,
        ];
    }

    /** @return array<string, string> */
    private function docVk(): array
    {
        return [
            'documentation_title' => 'Как подключить VK',
            'documentation_markdown' => <<<'MD'
### VK: сообщества и уведомления

1. Используйте **API сообщества** (ключ доступа в настройках сообщества) или **VK Ads / рассылки** для массовых акций.
2. Подсчёт аудитории в CRM синхронизируется после добавления соответствующей интеграции (этап roadmap). Сейчас отображается **0** до подключения.
3. Для OAuth-входа пользователей уже доступен раздел **Интеграции → ВКонтакте (OAuth)** — это отдельно от маркетинговых рассылок.
MD,
        ];
    }

    public function index(): JsonResponse
    {
        $smtp = MailIntegration::query()->where('name', 'smtp')->first();
        $emailConnected = $smtp !== null
            && $smtp->is_active
            && trim((string) ($smtp->config['host'] ?? '')) !== ''
            && trim((string) ($smtp->config['username'] ?? '')) !== ''
            && trim((string) ($smtp->config['password'] ?? '')) !== ''
            && trim((string) ($smtp->config['from_email'] ?? '')) !== '';

        $emailSubs = (int) User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('customer_profiles')
                    ->whereColumn('customer_profiles.user_id', 'users.id')
                    ->where('customer_profiles.marketing_opt_in', true);
            })
            ->count();

        $channels = [
            [
                'key' => 'email',
                'name' => 'Email',
                'icon' => '📧',
                'connected' => $emailConnected,
                'subscriber_count' => $emailSubs,
                ...$this->docEmail(),
            ],
            [
                'key' => 'telegram',
                'name' => 'Telegram',
                'icon' => '✈️',
                'connected' => false,
                'subscriber_count' => 0,
                ...$this->docTelegram(),
            ],
            [
                'key' => 'whatsapp',
                'name' => 'WhatsApp',
                'icon' => '💬',
                'connected' => false,
                'subscriber_count' => 0,
                ...$this->docWhatsapp(),
            ],
            [
                'key' => 'vk',
                'name' => 'VK',
                'icon' => '🔵',
                'connected' => false,
                'subscriber_count' => 0,
                ...$this->docVk(),
            ],
        ];

        return response()->json(['data' => $channels]);
    }
}
