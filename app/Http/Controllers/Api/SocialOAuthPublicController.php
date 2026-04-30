<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialOauthIntegration;
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
            $providers[] = [
                'id' => $name,
                'title' => $titles[$name] ?? $name,
                'enabled' => $enabled,
                'start_url' => $enabled ? URL::to('/api/v1/auth/social/'.$name.'/redirect') : null,
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

        $row = SocialOauthIntegration::where('name', $provider)->firstOrFail();

        if (! $row->is_active || ! $this->flow->isConfigured($provider, $row)) {
            return redirect()->away(
                $frontend.'/auth?social_error='.rawurlencode('Провайдер не включён или не заполнены поля в CRM → Интеграции → Соцсети')
            );
        }

        $state = Str::random(48);
        Cache::put(
            'social_oauth:'.$state,
            ['provider' => $provider, 'ip' => $request->ip()],
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

        $row = SocialOauthIntegration::where('name', $provider)->firstOrFail();
        $ex = $this->flow->exchangeCode($provider, $row, $code);

        if (! empty($ex['error'])) {
            return redirect()->away($frontend.'/auth?social_error='.rawurlencode((string) $ex['error']));
        }

        $row->update(['last_successful_oauth_at' => now()]);

        return redirect()->away($frontend.'/auth?social_login='.rawurlencode($provider).'&social_ok=1');
    }
}
