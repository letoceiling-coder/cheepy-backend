<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminRoleMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('auth_user_model');
        if (!$user || !$user->can('users.manage')) {
            return response()->json(['error' => 'Доступ запрещён. Требуется роль admin или право users.manage.'], 403);
        }
        return $next($request);
    }
}
