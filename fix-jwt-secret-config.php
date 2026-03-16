<?php
/**
 * Fix JWT_SECRET: add to config so it gets cached with config:cache.
 * Update AuthController to use config('auth.jwt_secret').
 */

$base = '/var/www/online-parser.siteaacess.store';

$authPath = $base . '/config/auth.php';
$auth = file_get_contents($authPath);
if (!str_contains($auth, "'jwt_secret'")) {
    $auth = str_replace("return [\n\n    /*", "return [\n\n    'jwt_secret' => env('JWT_SECRET'),\n\n    /*", $auth);
    file_put_contents($authPath, $auth);
    echo "Added jwt_secret to config/auth.php\n";
}

$ctrlPath = $base . '/app/Http/Controllers/Api/AuthController.php';
$ctrl = file_get_contents($ctrlPath);
$ctrl = str_replace("\$this->secret = env('JWT_SECRET', 'change_me');", "\$this->secret = config('auth.jwt_secret') ?: env('JWT_SECRET');", $ctrl);
$ctrl = str_replace("\$secret = env('JWT_SECRET', 'change_me');", "\$secret = config('auth.jwt_secret') ?: env('JWT_SECRET');", $ctrl);
file_put_contents($ctrlPath, $ctrl);
echo "Updated AuthController\n";
