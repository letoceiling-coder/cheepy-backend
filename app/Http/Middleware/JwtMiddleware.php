<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\AuthController;
use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['error' => 'Необходима авторизация'], 401);
        }

        $payload = AuthController::verifyToken($token);
        if (!$payload) {
            return response()->json(['error' => 'Недействительный токен'], 401);
        }

        $user = AdminUser::find($payload['sub']);
        if (!$user || !$user->is_active) {
            return response()->json(['error' => 'Пользователь не найден или деактивирован'], 401);
        }

        $request->attributes->set('auth_user', [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ]);
        $request->attributes->set('auth_user_model', $user);

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
