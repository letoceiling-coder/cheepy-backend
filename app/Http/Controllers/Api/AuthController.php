<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    private string $secret;

    public function __construct()
    {
        $this->secret = env('JWT_SECRET', 'change_me');
    }

    public function login(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');

        $user = AdminUser::where('email', $email)->where('is_active', true)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json(['error' => 'Неверные учётные данные'], 401);
        }

        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $payload = [
            'sub' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'iat' => time(),
            'exp' => time() + 86400 * 7,
        ];

        $token = JWT::encode($payload, $this->secret, 'HS256');

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        return response()->json(['user' => $user]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        $userId = $user['id'];

        $adminUser = AdminUser::find($userId);
        if (!$adminUser) {
            return response()->json(['error' => 'Пользователь не найден'], 404);
        }

        $payload = [
            'sub' => $adminUser->id,
            'email' => $adminUser->email,
            'role' => $adminUser->role,
            'iat' => time(),
            'exp' => time() + 86400 * 7,
        ];

        return response()->json(['token' => JWT::encode($payload, $this->secret, 'HS256')]);
    }

    public static function verifyToken(string $token): ?array
    {
        try {
            $secret = env('JWT_SECRET', 'change_me');
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            return (array) $decoded;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
