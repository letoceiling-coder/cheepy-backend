<?php
/**
 * One-off: output JWT for first admin. Run: php scripts/get-token.php
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require_once $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AdminUser;
use Firebase\JWT\JWT;

$user = AdminUser::where('is_active', true)->first();
if (!$user) {
    echo "No admin user\n";
    exit(1);
}

$secret = config('jwt.secret') ?: config('app.key');
$payload = [
    'sub' => $user->id,
    'email' => $user->email,
    'role' => $user->role,
    'iat' => time(),
    'exp' => time() + 86400,
];

$token = JWT::encode($payload, $secret, 'HS256');
echo $token . "\n";
