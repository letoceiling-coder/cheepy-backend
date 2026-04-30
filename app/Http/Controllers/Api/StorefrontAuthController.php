<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PhoneNormalizer;
use App\Support\SocialOauthCatalog;
use App\Support\StorefrontSmsGate;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StorefrontAuthController extends Controller
{
    /**
     * @return array{name: string, id: int, email: ?string, phone: ?string, linked_social_providers: list<string>}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'linked_social_providers' => $user->socialAccounts()->pluck('provider')->values()->all(),
        ];
    }

    public static function encodeCustomerSessionToken(User $user): string
    {
        $secret = (string) (config('jwt.secret') ?: config('app.key'));
        $days = (int) config('jwt.expires_days', 7);
        $payload = [
            'sub' => $user->id,
            'kind' => 'customer',
            'iat' => time(),
            'exp' => time() + max(1, $days) * 86400,
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }

    public static function encodeSocialLinkToken(User $user): string
    {
        $secret = (string) (config('jwt.secret') ?: config('app.key'));
        $payload = [
            'sub' => $user->id,
            'kind' => 'customer',
            'purpose' => 'social_link',
            'iat' => time(),
            'exp' => time() + 600,
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * @return array{sub: int}|null
     */
    public static function verifySocialLinkToken(string $token): ?array
    {
        try {
            $secret = (string) (config('jwt.secret') ?: config('app.key'));
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            $arr = (array) $decoded;
            if (($arr['kind'] ?? '') !== 'customer' || ($arr['purpose'] ?? '') !== 'social_link') {
                return null;
            }
            $sub = (int) ($arr['sub'] ?? 0);

            return $sub > 0 ? ['sub' => $sub] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{sub: int}|null
     */
    public static function verifyCustomerSessionToken(string $token): ?array
    {
        try {
            $secret = (string) (config('jwt.secret') ?: config('app.key'));
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            $arr = (array) $decoded;
            if (($arr['kind'] ?? '') !== 'customer') {
                return null;
            }
            if (($arr['purpose'] ?? '') !== '') {
                return null;
            }
            $sub = (int) ($arr['sub'] ?? 0);

            return $sub > 0 ? ['sub' => $sub] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $login = trim($data['login']);
        $password = $data['password'];

        $user = null;
        if (str_contains($login, '@')) {
            $user = User::query()->where('email', $login)->first();
        } else {
            $phone = PhoneNormalizer::normalize($login);
            if ($phone) {
                $user = User::query()->where('phone', $phone)->first();
            }
        }

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            return response()->json(['error' => 'Неверные учётные данные'], 401);
        }

        $token = self::encodeCustomerSessionToken($user);

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $smsOn = StorefrontSmsGate::phoneAuthEnabled();

        $rules = [
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ];

        if ($smsOn) {
            $rules['phone'][] = 'required';
        }

        $data = $request->validate($rules);

        $phoneNorm = PhoneNormalizer::normalize($data['phone'] ?? null);

        if ($smsOn && $phoneNorm === null) {
            return response()->json(['error' => 'Укажите корректный номер телефона'], 422);
        }

        if ($phoneNorm && User::query()->where('phone', $phoneNorm)->exists()) {
            return response()->json(['error' => 'Этот телефон уже зарегистрирован'], 422);
        }

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $phoneNorm,
            'password' => $data['password'],
        ]);

        $token = self::encodeCustomerSessionToken($user);

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('storefront_user');

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function refresh(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('storefront_user');
        $fresh = User::query()->find($user->id);
        if (! $fresh) {
            return response()->json(['error' => 'Пользователь не найден'], 404);
        }

        return response()->json(['token' => self::encodeCustomerSessionToken($fresh)]);
    }

    public function socialLinkSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:'.implode(',', SocialOauthCatalog::PROVIDERS)],
        ]);
        /** @var User $user */
        $user = $request->attributes->get('storefront_user');

        $linkJwt = self::encodeSocialLinkToken($user);
        $path = '/api/v1/auth/social/'.$data['provider'].'/redirect?link_token='.rawurlencode($linkJwt);
        $redirectUrl = rtrim((string) config('app.url'), '/').$path;

        return response()->json(['redirect_url' => $redirectUrl]);
    }

    /** Пароль для пользователя только через OAuth (хэшируется cast модели User). */
    public static function randomOAuthPasswordPlain(): string
    {
        return Str::random(64);
    }
}
