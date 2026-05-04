<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StorefrontAuthController;
use App\Models\AdminUser;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StorefrontJwtMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);
        if (! $token) {
            return response()->json(['error' => 'Необходима авторизация'], 401);
        }

        $payload = StorefrontAuthController::verifyCustomerSessionToken($token);
        if ($payload) {
            $user = User::query()->find($payload['sub']);
            if (! $user) {
                return response()->json(['error' => 'Пользователь не найден'], 401);
            }

            $request->attributes->set('storefront_user', $user);
            $request->attributes->set('storefront_auth_role', $user->account_role ?? 'customer');

            return $next($request);
        }

        $adminPayload = AuthController::verifyToken($token);
        if (! $adminPayload) {
            return response()->json(['error' => 'Недействительный токен'], 401);
        }

        $admin = AdminUser::query()->find((int) ($adminPayload['sub'] ?? 0));
        if (! $admin || ! $admin->is_active || $admin->role !== 'admin') {
            return response()->json(['error' => 'Недостаточно прав'], 403);
        }

        $user = User::query()->firstOrCreate(
            ['email' => $admin->email],
            [
                'name' => $admin->name,
                'phone' => null,
                'account_role' => 'admin',
                'password' => null,
            ]
        );
        if ($user->account_role !== 'admin') {
            $user->update(['account_role' => 'admin']);
        }

        $request->attributes->set('storefront_user', $user);
        $request->attributes->set('storefront_auth_role', 'admin');

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $auth = $request->header('Authorization');
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }

        return $request->query('token');
    }
}
