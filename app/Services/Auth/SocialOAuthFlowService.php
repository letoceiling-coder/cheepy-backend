<?php

namespace App\Services\Auth;

use App\Models\SocialOauthIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Построение URL авторизации и обмен authorization code на токены (официальные endpoint’ы провайдеров).
 */
class SocialOAuthFlowService
{
    public function isConfigured(string $provider, SocialOauthIntegration $row): bool
    {
        $c = $row->config ?? [];

        return match ($provider) {
            'vk' => trim((string) ($c['client_id'] ?? '')) !== ''
                && trim((string) ($c['client_secret'] ?? '')) !== '',
            'yandex' => trim((string) ($c['client_id'] ?? '')) !== ''
                && trim((string) ($c['client_secret'] ?? '')) !== '',
            'ok' => trim((string) ($c['application_id'] ?? '')) !== ''
                && trim((string) ($c['secret_key'] ?? '')) !== ''
                && trim((string) ($c['public_key'] ?? '')) !== '',
            default => false,
        };
    }

    public function redirectUriForIntegration(string $provider, SocialOauthIntegration $row): string
    {
        $config = $row->config ?? [];
        $override = trim((string) ($config['redirect_uri_override'] ?? ''));

        return $override !== '' ? $override : $this->defaultBackendCallbackUrl($provider);
    }

    public function defaultBackendCallbackUrl(string $provider): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/auth/social/'.$provider.'/callback';
    }

    /**
     * @return array{url: string, error?: string}
     */
    public function buildAuthorizeUrl(string $provider, SocialOauthIntegration $row, string $state): array
    {
        $config = $row->config ?? [];
        $redirectUri = $this->redirectUriForIntegration($provider, $row);

        return match ($provider) {
            'vk' => $this->buildVkAuthorize($config, $redirectUri, $state),
            'yandex' => $this->buildYandexAuthorize($config, $redirectUri, $state),
            'ok' => $this->buildOkAuthorize($config, $redirectUri, $state),
            default => ['url' => '', 'error' => 'Неизвестный провайдер'],
        };
    }

    /**
     * @param array<string, mixed> $config
     * @return array{access_token?: string, expires_in?: int, refresh_token?: string, error?: string, raw?: mixed}
     */
    public function exchangeCode(string $provider, SocialOauthIntegration $row, string $code): array
    {
        $config = $row->config ?? [];
        $redirectUri = $this->redirectUriForIntegration($provider, $row);

        return match ($provider) {
            'vk' => $this->exchangeVk($config, $redirectUri, $code),
            'yandex' => $this->exchangeYandex($config, $redirectUri, $code),
            'ok' => $this->exchangeOk($config, $redirectUri, $code),
            default => ['error' => 'Неизвестный провайдер'],
        };
    }

    /**
     * @param array<string, mixed> $config
     * @return array{url: string, error?: string}
     */
    private function buildVkAuthorize(array $config, string $redirectUri, string $state): array
    {
        $clientId = trim((string) ($config['client_id'] ?? ''));
        if ($clientId === '') {
            return ['url' => '', 'error' => 'Не задан Client ID (ID приложения VK).'];
        }

        $scope = trim((string) ($config['scope_override'] ?? 'email'));
        if ($scope === '') {
            $scope = 'email';
        }

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'display' => 'page',
            'scope' => $scope,
            'response_type' => 'code',
            'v' => '5.131',
            'state' => $state,
        ]);

        return ['url' => 'https://oauth.vk.com/authorize?'.$query];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{url: string, error?: string}
     */
    private function buildYandexAuthorize(array $config, string $redirectUri, string $state): array
    {
        $clientId = trim((string) ($config['client_id'] ?? ''));
        if ($clientId === '') {
            return ['url' => '', 'error' => 'Не задан Client ID Яндекса.'];
        }

        $scope = trim((string) ($config['scope_override'] ?? 'login:email login:info'));
        if ($scope === '') {
            $scope = 'login:email login:info';
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $state,
        ]);

        return ['url' => 'https://oauth.yandex.ru/authorize?'.$query];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{url: string, error?: string}
     */
    private function buildOkAuthorize(array $config, string $redirectUri, string $state): array
    {
        $appId = trim((string) ($config['application_id'] ?? ''));
        if ($appId === '') {
            return ['url' => '', 'error' => 'Не задан Application ID OK.'];
        }

        $scope = trim((string) ($config['scope_override'] ?? 'VALUABLE_ACCESS;GET_EMAIL'));
        if ($scope === '') {
            $scope = 'VALUABLE_ACCESS;GET_EMAIL';
        }

        $query = http_build_query([
            'client_id' => $appId,
            'scope' => $scope,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return ['url' => 'https://connect.ok.ru/oauth/authorize?'.$query];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{access_token?: string, expires_in?: int, refresh_token?: string, error?: string, raw?: mixed}
     */
    private function exchangeVk(array $config, string $redirectUri, string $code): array
    {
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $secret = trim((string) ($config['client_secret'] ?? ''));
        if ($clientId === '' || $secret === '') {
            return ['error' => 'Не заданы client_id или client_secret VK'];
        }

        try {
            $res = Http::timeout(25)->get('https://oauth.vk.com/access_token', [
                'client_id' => $clientId,
                'client_secret' => $secret,
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]);
        } catch (\Throwable $e) {
            Log::warning('vk oauth token transport', ['e' => $e->getMessage()]);

            return ['error' => 'Сеть: '.$e->getMessage()];
        }

        $json = $res->json();
        if (! $res->successful() || ! is_array($json)) {
            return ['error' => 'VK token HTTP '.$res->status(), 'raw' => $json ?? $res->body()];
        }

        if (! empty($json['error'])) {
            return ['error' => is_string($json['error']) ? $json['error'] : 'VK OAuth error', 'raw' => $json];
        }

        $token = $json['access_token'] ?? null;

        return is_string($token) && $token !== ''
            ? ['access_token' => $token, 'expires_in' => isset($json['expires_in']) ? (int) $json['expires_in'] : null, 'raw' => $json]
            : ['error' => 'VK не вернул access_token', 'raw' => $json];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{access_token?: string, expires_in?: int, refresh_token?: string, error?: string, raw?: mixed}
     */
    private function exchangeYandex(array $config, string $redirectUri, string $code): array
    {
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $secret = trim((string) ($config['client_secret'] ?? ''));
        if ($clientId === '' || $secret === '') {
            return ['error' => 'Не заданы client_id или client_secret Яндекса'];
        }

        try {
            $res = Http::asForm()->timeout(25)->post('https://oauth.yandex.ru/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $secret,
                'redirect_uri' => $redirectUri,
            ]);
        } catch (\Throwable $e) {
            Log::warning('yandex oauth token transport', ['e' => $e->getMessage()]);

            return ['error' => 'Сеть: '.$e->getMessage()];
        }

        $json = $res->json();
        if (! $res->successful() || ! is_array($json)) {
            return ['error' => 'Yandex token HTTP '.$res->status(), 'raw' => $json ?? $res->body()];
        }

        if (! empty($json['error'])) {
            return ['error' => (string) ($json['error_description'] ?? $json['error']), 'raw' => $json];
        }

        $token = $json['access_token'] ?? null;

        return is_string($token) && $token !== ''
            ? [
                'access_token' => $token,
                'expires_in' => isset($json['expires_in']) ? (int) $json['expires_in'] : null,
                'refresh_token' => isset($json['refresh_token']) ? (string) $json['refresh_token'] : null,
                'raw' => $json,
            ]
            : ['error' => 'Яндекс не вернул access_token', 'raw' => $json];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{access_token?: string, expires_in?: int, refresh_token?: string, error?: string, raw?: mixed}
     */
    private function exchangeOk(array $config, string $redirectUri, string $code): array
    {
        $appId = trim((string) ($config['application_id'] ?? ''));
        $secret = trim((string) ($config['secret_key'] ?? ''));
        $publicKey = trim((string) ($config['public_key'] ?? ''));
        if ($appId === '' || $secret === '') {
            return ['error' => 'Не заданы Application ID или секретный ключ OK'];
        }

        $body = [
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
            'client_id' => $appId,
            'client_secret' => $secret,
        ];
        if ($publicKey !== '') {
            $body['application_key'] = $publicKey;
        }

        try {
            $res = Http::asForm()->timeout(25)->post('https://api.ok.ru/oauth/token.do', $body);
        } catch (\Throwable $e) {
            Log::warning('ok oauth token transport', ['e' => $e->getMessage()]);

            return ['error' => 'Сеть: '.$e->getMessage()];
        }

        $json = $res->json();
        if (! $res->successful() || ! is_array($json)) {
            return ['error' => 'OK token HTTP '.$res->status(), 'raw' => $json ?? $res->body()];
        }

        if (! empty($json['error'])) {
            return ['error' => is_string($json['error']) ? $json['error'] : 'OK OAuth error', 'raw' => $json];
        }

        $token = $json['access_token'] ?? null;

        return is_string($token) && $token !== ''
            ? [
                'access_token' => $token,
                'expires_in' => isset($json['expires_in']) ? (int) $json['expires_in'] : null,
                'refresh_token' => isset($json['refresh_token']) ? (string) $json['refresh_token'] : null,
                'raw' => $json,
            ]
            : ['error' => 'OK не вернул access_token', 'raw' => $json];
    }

    /**
     * @param  array<string, mixed>  $exchangeRaw  ответ token endpoint (VK/Yandex/OK)
     * @param  array<string, mixed>  $config  конфиг интеграции из CRM
     * @return array{provider_user_id: string, email: ?string, name: ?string, error?: string}
     */
    public function resolveSocialIdentity(string $provider, string $accessToken, array $exchangeRaw, array $config): array
    {
        return match ($provider) {
            'vk' => $this->resolveVkIdentity($accessToken, $exchangeRaw),
            'yandex' => $this->resolveYandexIdentity($accessToken),
            'ok' => $this->resolveOkIdentity($accessToken, $exchangeRaw, $config),
            default => ['provider_user_id' => '', 'email' => null, 'name' => null, 'error' => 'Неизвестный провайдер'],
        };
    }

    /**
     * @param  array<string, mixed>  $exchangeRaw
     * @return array{provider_user_id: string, email: ?string, name: ?string, error?: string}
     */
    private function resolveVkIdentity(string $accessToken, array $exchangeRaw): array
    {
        $email = isset($exchangeRaw['email']) && is_string($exchangeRaw['email']) ? $exchangeRaw['email'] : null;
        $uid = $exchangeRaw['user_id'] ?? null;
        if (is_numeric($uid)) {
            $name = null;

            return [
                'provider_user_id' => (string) $uid,
                'email' => $email !== '' ? $email : null,
                'name' => $name,
            ];
        }

        try {
            $res = Http::timeout(20)->get('https://api.vk.com/method/users.get', [
                'access_token' => $accessToken,
                'v' => '5.131',
                'fields' => 'photo_200',
            ]);
        } catch (\Throwable $e) {
            Log::warning('vk users.get transport', ['e' => $e->getMessage()]);

            return ['provider_user_id' => '', 'email' => $email, 'name' => null, 'error' => 'Сеть: '.$e->getMessage()];
        }

        $json = $res->json();
        $list = is_array($json['response'] ?? null) ? $json['response'] : [];
        $first = isset($list[0]) && is_array($list[0]) ? $list[0] : [];
        $id = $first['id'] ?? null;
        if (! is_numeric($id)) {
            return ['provider_user_id' => '', 'email' => $email, 'name' => null, 'error' => 'VK: не удалось получить id пользователя'];
        }

        $fn = isset($first['first_name']) && is_string($first['first_name']) ? $first['first_name'] : '';
        $ln = isset($first['last_name']) && is_string($first['last_name']) ? $first['last_name'] : '';
        $name = trim($fn.' '.$ln);

        return [
            'provider_user_id' => (string) $id,
            'email' => $email !== '' ? $email : null,
            'name' => $name !== '' ? $name : null,
        ];
    }

    /**
     * @return array{provider_user_id: string, email: ?string, name: ?string, error?: string}
     */
    private function resolveYandexIdentity(string $accessToken): array
    {
        try {
            $res = Http::timeout(20)
                ->withHeaders(['Authorization' => 'OAuth '.$accessToken])
                ->get('https://login.yandex.ru/info');
        } catch (\Throwable $e) {
            Log::warning('yandex info transport', ['e' => $e->getMessage()]);

            return ['provider_user_id' => '', 'email' => null, 'name' => null, 'error' => 'Сеть: '.$e->getMessage()];
        }

        $json = $res->json();
        if (! $res->successful() || ! is_array($json)) {
            return ['provider_user_id' => '', 'email' => null, 'name' => null, 'error' => 'Яндекс profile HTTP '.$res->status()];
        }

        $id = $json['id'] ?? $json['client_id'] ?? null;
        if ($id === null || $id === '') {
            return ['provider_user_id' => '', 'email' => null, 'name' => null, 'error' => 'Яндекс: нет id'];
        }

        $email = isset($json['default_email']) && is_string($json['default_email']) ? $json['default_email'] : null;
        $login = isset($json['login']) && is_string($json['login']) ? $json['login'] : null;
        $email = $email ?? ($login ? $login.'@yandex.ru' : null);

        $name = null;
        if (isset($json['real_name']) && is_string($json['real_name']) && trim($json['real_name']) !== '') {
            $name = trim($json['real_name']);
        } elseif (isset($json['display_name']) && is_string($json['display_name'])) {
            $name = trim($json['display_name']);
        }

        return [
            'provider_user_id' => (string) $id,
            'email' => $email,
            'name' => $name,
        ];
    }

    /**
     * @param  array<string, mixed>  $exchangeRaw
     * @param  array<string, mixed>  $config
     * @return array{provider_user_id: string, email: ?string, name: ?string, error?: string}
     */
    private function resolveOkIdentity(string $accessToken, array $exchangeRaw, array $config): array
    {
        $publicKey = trim((string) ($config['public_key'] ?? ''));
        $secretKey = trim((string) ($config['secret_key'] ?? ''));
        if ($publicKey === '' || $secretKey === '') {
            return ['provider_user_id' => '', 'email' => null, 'name' => null, 'error' => 'OK: не заданы ключи приложения'];
        }

        $params = [
            'application_key' => $publicKey,
            'format' => 'json',
            'method' => 'users.getCurrentUser',
            'session_key' => $accessToken,
        ];
        ksort($params);
        $sigBase = '';
        foreach ($params as $k => $v) {
            $sigBase .= $k.'='.$v;
        }
        $sessionSecret = md5($accessToken.$secretKey);
        $params['sig'] = md5($sigBase.md5($sessionSecret));

        try {
            $res = Http::timeout(20)->get('https://api.ok.ru/fetch.do', $params);
        } catch (\Throwable $e) {
            Log::warning('ok fetch transport', ['e' => $e->getMessage()]);

            return ['provider_user_id' => '', 'email' => null, 'name' => null, 'error' => 'Сеть: '.$e->getMessage()];
        }

        $json = $res->json();
        if (! $res->successful()) {
            return ['provider_user_id' => '', 'email' => null, 'name' => null, 'error' => 'OK profile HTTP '.$res->status()];
        }

        if (isset($json['error_code'])) {
            return ['provider_user_id' => '', 'email' => null, 'name' => null, 'error' => 'OK API: '.json_encode($json['error_msg'] ?? $json)];
        }

        $uid = $json['uid'] ?? null;

        if ($uid === null || $uid === '') {
            return ['provider_user_id' => '', 'email' => null, 'name' => null, 'error' => 'OK: не удалось получить uid'];
        }

        $name = null;
        if (isset($json['name']) && is_string($json['name'])) {
            $name = trim($json['name']);
        }

        $email = null;
        if (isset($json['email']) && is_string($json['email'])) {
            $email = trim($json['email']);
        }

        return [
            'provider_user_id' => (string) $uid,
            'email' => $email !== '' ? $email : null,
            'name' => $name !== '' ? $name : null,
        ];
    }
}
