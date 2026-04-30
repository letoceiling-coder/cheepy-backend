<?php

namespace App\Support;

/**
 * OAuth вход через VK / Яндекс / Одноклассники: метаданные и пошаговые инструкции (ориентир на официальную документацию провайдеров).
 */
final class SocialOauthCatalog
{
    public const PROVIDERS = ['vk', 'yandex', 'ok'];

    /**
     * @return array{
     *   title: string,
     *   official_documentation: list<array{title: string, url: string}>,
     *   recommended_scopes: string,
     *   steps: list<array{title: string, body: string}>,
     *   notes: string
     * }
     */
    public static function documentation(string $name): array
    {
        return match ($name) {
            'vk' => self::docVk(),
            'yandex' => self::docYandex(),
            'ok' => self::docOk(),
            default => [
                'title' => $name,
                'official_documentation' => [],
                'recommended_scopes' => '',
                'steps' => [],
                'notes' => '',
            ],
        };
    }

    /**
     * @return array{
     *   title: string,
     *   official_documentation: list<array{title: string, url: string}>,
     *   recommended_scopes: string,
     *   steps: list<array{title: string, body: string}>,
     *   notes: string
     * }
     */
    private static function docVk(): array
    {
        return [
            'title' => 'ВКонтакте (VK ID / OAuth)',
            'official_documentation' => [
                ['title' => 'VK для разработчиков — создание приложения', 'url' => 'https://dev.vk.com/ru/guide/create-app'],
                ['title' => 'VK ID — документация для бизнеса', 'url' => 'https://id.vk.com/about/business/go/docs/ru/vkid/latest/vk-id/connection/start-integration'],
                ['title' => 'Authorization Code Flow (веб)', 'url' => 'https://id.vk.com/about/business/go/docs/ru/vkid/latest/vk-id/connection/oauth/code-flow-web'],
                ['title' => 'Получение access_token (справочник API)', 'url' => 'https://dev.vk.com/ru/api/access-token/authcode-flow-user'],
            ],
            'recommended_scopes' => 'email (и при необходимости openid; точный набор зависит от типа приложения и политики VK ID — сверяйте с текущей документацией).',
            'steps' => [
                [
                    'title' => 'Шаг 1. Создайте приложение VK',
                    'body' => 'Войдите в кабинет разработчика VK (dev.vk.com), создайте приложение под ваш сайт. Укажите платформу «Веб» или сценарий, соответствующий авторизации пользователей на сайте.',
                ],
                [
                    'title' => 'Шаг 2. Зафиксируйте Redirect URI',
                    'body' => 'В настройках приложения добавьте Redirect URI ровно такой, как показано в CRM в поле «Callback URL для регистрации у провайдера» (это URL вашего backend API …/api/v1/auth/social/vk/callback). Лишние слэши и HTTP vs HTTPS должны совпадать.',
                ],
                [
                    'title' => 'Шаг 3. Скопируйте идентификаторы',
                    'body' => 'Сохраните в CRM: ID приложения (client_id), Защищённый ключ (client_secret). Сервисный ключ доступа — опционально: нужен для серверных вызовов от имени приложения (не для цепочки OAuth пользователя); получите в настройках приложения, если используете такие методы.',
                ],
                [
                    'title' => 'Шаг 4. Включите провайдера и проверьте вход',
                    'body' => 'В CRM включите интеграцию VK. На странице входа витрины кнопка «VK» ведёт на oauth.vk.com (или актуальный authorize URL VK ID — см. документацию). После успешного входа пользователь возвращается на сайт; выпуск JWT для покупателя будет добавлен отдельно при подключении профиля к БД.',
                ],
            ],
            'notes' => 'VK периодически обновляет VK ID и параметры OAuth. Всегда сверяйте список полей формы авторизации и обязательные scope с официальной документацией по ссылкам выше.',
        ];
    }

    /**
     * @return array{
     *   title: string,
     *   official_documentation: list<array{title: string, url: string}>,
     *   recommended_scopes: string,
     *   steps: list<array{title: string, body: string}>,
     *   notes: string
     * }
     */
    private static function docYandex(): array
    {
        return [
            'title' => 'Яндекс ID (OAuth)',
            'official_documentation' => [
                ['title' => 'Яндекс OAuth — общее описание', 'url' => 'https://yandex.ru/dev/id/doc/ru/'],
                ['title' => 'Регистрация приложения OAuth', 'url' => 'https://yandex.ru/dev/id/doc/ru/register-client'],
                ['title' => 'Код подтверждения из redirect_uri (Authorization Code)', 'url' => 'https://yandex.ru/dev/id/doc/ru/codes/code-url'],
            ],
            'recommended_scopes' => 'login:email login:info (при необходимости добавьте login:avatar и другие по документации Яндекса).',
            'steps' => [
                [
                    'title' => 'Шаг 1. Зарегистрируйте OAuth-приложение',
                    'body' => 'Перейдите в сервис регистрации OAuth-приложений Яндекса. Создайте приложение типа «Для авторизации», укажите название и платформу «Веб-сервисы».',
                ],
                [
                    'title' => 'Шаг 2. Callback URI',
                    'body' => 'В поле Redirect URI укажите значение из CRM — URL вида …/api/v1/auth/social/yandex/callback на вашем API-хосте (тот же хост, что APP_URL в Laravel).',
                ],
                [
                    'title' => 'Шаг 3. Идентификатор и секрет',
                    'body' => 'Скопируйте Client ID → поле «Идентификатор приложения», Client secret → «Секрет». Секрет показывается один раз при создании — сохраните его надёжно.',
                ],
                [
                    'title' => 'Шаг 4. Права (scopes)',
                    'body' => 'В настройках приложения отметьте нужные доступы (минимум — доступ к адресу электронной почты и базовой информации профиля). Список имён прав см. в документации Яндекс ID.',
                ],
                [
                    'title' => 'Шаг 5. Включение на витрине',
                    'body' => 'В CRM сохраните настройки и включите провайдера. Кнопка «Яндекс» на странице входа отправит пользователя на oauth.yandex.ru для выдачи кода.',
                ],
            ],
            'notes' => 'Яндекс требует точного совпадения redirect_uri при обмене кода на токен. При смене домена API обновите URI и в кабинете Яндекса, и в CRM.',
        ];
    }

    /**
     * @return array{
     *   title: string,
     *   official_documentation: list<array{title: string, url: string}>,
     *   recommended_scopes: string,
     *   steps: list<array{title: string, body: string}>,
     *   notes: string
     * }
     */
    private static function docOk(): array
    {
        return [
            'title' => 'Одноклассники (OAuth)',
            'official_documentation' => [
                ['title' => 'Подключение сайта / приложения (разработчикам)', 'url' => 'https://apiok.ru/ext/oauth/'],
                ['title' => 'Создание внешнего приложения', 'url' => 'https://apiok.ru/dev/app/create'],
            ],
            'recommended_scopes' => 'VALUABLE_ACCESS;GET_EMAIL (при необходимости расширьте список по задачам и правилам OK).',
            'steps' => [
                [
                    'title' => 'Шаг 1. Создайте приложение в OK',
                    'body' => 'Зайдите в раздел для разработчиков Одноклассников (apiok.ru), создайте внешнее приложение и заполните базовые данные.',
                ],
                [
                    'title' => 'Шаг 2. Redirect URI',
                    'body' => 'Укажите callback из CRM: …/api/v1/auth/social/ok/callback на вашем backend. Домен и протокол должны совпадать с тем, что реально открывает браузер после авторизации.',
                ],
                [
                    'title' => 'Шаг 3. Ключи приложения',
                    'body' => 'Сохраните в CRM три значения из кабинета OK: Application ID (идентификатор приложения), Публичный ключ приложения, Секретный ключ приложения. Они используются в запросах OAuth и API.',
                ],
                [
                    'title' => 'Шаг 4. Проверка сценария',
                    'body' => 'Включите провайдера в CRM. Кнопка «OK» открывает страницу авторизации connect.ok.ru; после подтверждения OK перенаправляет на callback backend с параметром code.',
                ],
            ],
            'notes' => 'Формат параметров token endpoint OK может отличаться от других провайдеров; при ошибках обмена кода проверьте актуальный раздел «OAuth» на apiok.ru.',
        ];
    }
}
