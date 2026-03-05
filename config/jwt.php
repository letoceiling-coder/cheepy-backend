<?php

return [
    'secret' => env('JWT_SECRET', ''),
    'expires_days' => (int) env('JWT_EXPIRES_DAYS', 7),
];
