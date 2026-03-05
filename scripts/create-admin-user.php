<?php
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require_once $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AdminUser;
use Illuminate\Support\Facades\Hash;

$user = AdminUser::updateOrCreate(
    ['email' => 'dsc-23@yandex.ru'],
    [
        'name' => 'Джон Уик',
        'password' => Hash::make('123123123'),
        'role' => 'admin',
        'is_active' => true,
    ]
);
echo "OK id={$user->id} email={$user->email} role={$user->role}\n";
