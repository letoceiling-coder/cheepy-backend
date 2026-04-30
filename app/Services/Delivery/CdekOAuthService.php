<?php

namespace App\Services\Delivery;

use Illuminate\Support\Facades\Http;

/**
 * Клиентская авторизация СДЭК API v2 (OAuth 2.0, grant_type=client_credentials).
 *
 * @see https://apidoc.cdek.ru/
 */
class CdekOAuthService
{
    public const ENV_PRODUCTION = 'production';

    public const ENV_INTEGRATION = 'integration';

    public function apiBase(string $environment): string
    {
        return $environment === self::ENV_INTEGRATION
            ? 'https://api.edu.cdek.ru'
            : 'https://api.cdek.ru';
    }

    public function oauthTokenUrl(string $environment): string
    {
        return $this->apiBase($environment).'/v2/oauth/token';
    }

    /**
     * @return array{success: bool, message: string, raw_status?: int}
     */
    public function requestToken(string $clientId, string $clientSecret, string $environment): array
    {
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        if ($clientId === '' || $clientSecret === '') {
            return ['success' => false, 'message' => 'Укажите идентификатор аккаунта и секретный ключ'];
        }

        $env = $environment === self::ENV_INTEGRATION ? self::ENV_INTEGRATION : self::ENV_PRODUCTION;
        $url = $this->oauthTokenUrl($env);

        try {
            $response = Http::asForm()
                ->timeout(25)
                ->acceptJson()
                ->post($url, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Сеть: '.$e->getMessage()];
        }

        $status = $response->status();
        $body = $response->json();

        if ($response->successful() && is_array($body) && ! empty($body['access_token'])) {
            $scope = isset($body['scope']) ? (string) $body['scope'] : '';

            return [
                'success' => true,
                'message' => $scope !== ''
                    ? 'OAuth успешно: получен access_token (scope: '.$scope.')'
                    : 'OAuth успешно: получен access_token',
                'raw_status' => $status,
            ];
        }

        $msg = 'HTTP '.$status;
        if (is_array($body)) {
            $err = $body['error_description'] ?? $body['error'] ?? $body['message'] ?? null;
            if (is_string($err) && $err !== '') {
                $msg .= ': '.$err;
            }
        } else {
            $txt = trim((string) $response->body());
            if ($txt !== '') {
                $msg .= ': '.mb_substr($txt, 0, 500);
            }
        }

        return ['success' => false, 'message' => $msg, 'raw_status' => $status];
    }
}
