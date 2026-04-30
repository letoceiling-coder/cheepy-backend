<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialOauthIntegration;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\Auth\SocialOAuthFlowService;
use App\Support\SocialOauthCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class SocialOAuthPublicController extends Controller
{
    public function __construct(
        private SocialOAuthFlowService $flow
    ) {}

    public function meta(): JsonResponse
    {
        $titles = [
            'vk' => 'VK',
            'yandex' => 'Яндекс',
            'ok' => 'OK',
        ];

        $rows = SocialOauthIntegration::query()
            ->whereIn('name', SocialOauthCatalog::PROVIDERS)
            ->get()
            ->keyBy(fn (SocialOauthIntegration $r) => $r->name);

        $providers = [];
        foreach (SocialOauthCatalog::PROVIDERS as $name) {
            $row = $rows->get($name);
            if (! $row) {
                continue;
            }
            $configured = $this->flow->isConfigured($name, $row);
            $enabled = $row->is_active && $configured;
            if (! $enabled) {
                continue;
            }
            $providers[] = [
                'id' => $name,
                'title' => $titles[$name] ?? $name,
                'enabled' => true,
                'start_url' => URL::to('/api/v1/auth/social/'.$name.'/redirect'),
            ];
        }

        return response()->json(['providers' => $providers]);
    }

    private function frontendBase(): string
    {
        $frontend = rtrim((string) config('services.social_oauth.frontend_base_url'), '/');

        return $frontend !== '' ? $frontend : rtrim((string) config('app.url'), '/');
    }

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        if (! in_array($provider, SocialOauthCatalog::PROVIDERS, true)) {
            abort(404);
        }

        $frontend = $this->frontendBase();

        $linkUserId = null;
        $linkToken = (string) $request->query('link_token', '');
        if ($linkToken !== '') {
            $verified = StorefrontAuthController::verifySocialLinkToken($linkToken);
            if ($verified === null) {
                return redirect()->away(
                    $frontend.'/auth?social_error='.rawurlencode('Сессия привязки истекла или недействительна. Повторите из личного кабинета.')
                );
            }
            $linkUserId = $verified['sub'];
        }

        $row = SocialOauthIntegration::where('name', $provider)->firstOrFail();

        if (! $row->is_active || ! $this->flow->isConfigured($provider, $row)) {
            return redirect()->away(
                $frontend.'/auth?social_error='.rawurlencode('Провайдер не включён или не заполнены поля в CRM → Интеграции → Соцсети')
            );
        }

        $state = Str::random(48);
        Cache::put(
            'social_oauth:'.$state,
            [
                'provider' => $provider,
                'ip' => $request->ip(),
                'link_user_id' => $linkUserId,
            ],
            now()->addMinutes(12)
        );

        $built = $this->flow->buildAuthorizeUrl($provider, $row, $state);
        if (($built['url'] ?? '') === '') {
            return redirect()->away(
                $frontend.'/auth?social_error='.rawurlencode((string) ($built['error'] ?? 'Не удалось собрать URL авторизации'))
            );
        }

        return redirect()->away($built['url']);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        if (! in_array($provider, SocialOauthCatalog::PROVIDERS, true)) {
            abort(404);
        }

        $frontend = $this->frontendBase();

        $error = $request->query('error')
            ?? $request->query('error_description');
        if (is_string($error) && $error !== '') {
            return redirect()->away($frontend.'/auth?social_error='.rawurlencode($error));
        }

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        if ($state === '' || $code === '') {
            return redirect()->away($frontend.'/auth?social_error='.rawurlencode('missing_code_or_state'));
        }

        $cacheKey = 'social_oauth:'.$state;
        $payload = Cache::pull($cacheKey);
        if (! is_array($payload) || ($payload['provider'] ?? '') !== $provider) {
            return redirect()->away($frontend.'/auth?social_error='.rawurlencode('invalid_state'));
        }

        $linkUserId = (int) ($payload['link_user_id'] ?? 0);

        $row = SocialOauthIntegration::where('name', $provider)->firstOrFail();
        $ex = $this->flow->exchangeCode($provider, $row, $code);

        if (! empty($ex['error'])) {
            return redirect()->away($frontend.'/auth?social_error='.rawurlencode((string) $ex['error']));
        }

        $accessToken = $ex['access_token'] ?? null;
        if (! is_string($accessToken) || $accessToken === '') {
            return redirect()->away($frontend.'/auth?social_error='.rawurlencode('missing_access_token'));
        }

        $exchangeRaw = is_array($ex['raw'] ?? null) ? $ex['raw'] : [];
        $identity = $this->flow->resolveSocialIdentity($provider, $accessToken, $exchangeRaw, $row->config ?? []);
        if (! empty($identity['error'])) {
            return redirect()->away($frontend.'/auth?social_error='.rawurlencode((string) $identity['error']));
        }

        $pid = $identity['provider_user_id'];
        if ($pid === '') {
            return redirect()->away($frontend.'/auth?social_error='.rawurlencode('empty_provider_user_id'));
        }

        $row->update(['last_successful_oauth_at' => now()]);

        if ($linkUserId > 0) {
            return $this->finishSocialLink($frontend, $provider, $pid, $linkUserId);
        }

        return $this->finishSocialLogin($frontend, $provider, $pid, $identity);
    }

    private function finishSocialLink(string $frontend, string $provider, string $providerUserId, int $linkUserId): RedirectResponse
    {
        $user = User::query()->find($linkUserId);
        if (! $user) {
            return redirect()->away($frontend.'/auth?social_error='.rawurlencode('Пользователь не найден'));
        }

        $taken = UserSocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($taken && $taken->user_id !== $user->id) {
            return redirect()->away($frontend.'/auth?social_error='.rawurlencode('Этот аккаунт соцсети уже привязан к другому пользователю'));
        }

        $sameProvider = UserSocialAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->first();

        if ($sameProvider && $sameProvider->provider_user_id !== $providerUserId) {
            return redirect()->away($frontend.'/auth?social_error='.rawurlencode('Уже есть другая привязка к этому провайдеру'));
        }

        if (! $sameProvider) {
            UserSocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
            ]);
        }

        $token = StorefrontAuthController::encodeCustomerSessionToken($user);

        return redirect()->away($frontend.'/auth#customer_token='.rawurlencode($token).'&social_linked='.rawurlencode($provider));
    }

    private function finishSocialLogin(string $frontend, string $provider, string $providerUserId, array $identity): RedirectResponse
    {
        $social = UserSocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($social) {
            $user = $social->user;
            if (! $user) {
                return redirect()->away($frontend.'/auth?social_error='.rawurlencode('Неконсистентные данные аккаунта'));
            }
            $token = StorefrontAuthController::encodeCustomerSessionToken($user);

            return redirect()->away($frontend.'/auth#customer_token='.rawurlencode($token));
        }

        $email = isset($identity['email']) && is_string($identity['email']) ? trim($identity['email']) : null;
        $email = $email !== '' ? $email : null;

        $user = null;
        if ($email !== null) {
            $user = User::query()->where('email', $email)->first();
        }

        if ($user) {
            $sameProv = UserSocialAccount::query()
                ->where('user_id', $user->id)
                ->where('provider', $provider)
                ->first();
            if ($sameProv && $sameProv->provider_user_id !== $providerUserId) {
                return redirect()->away($frontend.'/auth?social_error='.rawurlencode(
                    'У аккаунта уже привязан другой профиль этой соцсети. Войдите через неё или отвяжите в поддержке.'
                ));
            }
            if ($sameProv && $sameProv->provider_user_id === $providerUserId) {
                $token = StorefrontAuthController::encodeCustomerSessionToken($user);

                return redirect()->away($frontend.'/auth#customer_token='.rawurlencode($token));
            }
        }

        if (! $user) {
            $name = isset($identity['name']) && is_string($identity['name']) ? trim($identity['name']) : '';
            if ($name === '') {
                $name = match ($provider) {
                    'vk' => 'Пользователь VK',
                    'yandex' => 'Пользователь Яндекс',
                    'ok' => 'Пользователь OK',
                    default => 'Пользователь',
                };
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'phone' => null,
                'password' => StorefrontAuthController::randomOAuthPasswordPlain(),
            ]);
        }

        UserSocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
        ]);

        $token = StorefrontAuthController::encodeCustomerSessionToken($user);

        return redirect()->away($frontend.'/auth#customer_token='.rawurlencode($token));
    }
}
