<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\StorefrontAuthController;
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
        if (! $payload) {
            return response()->json(['error' => 'Недействительный токен'], 401);
        }

        $user = User::query()->find($payload['sub']);
        if (! $user) {
            return response()->json(['error' => 'Пользователь не найден'], 401);
        }

        $request->attributes->set('storefront_user', $user);

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
