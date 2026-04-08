<?php

return [
    'stripe' => [
        'currency' => env('PAYMENTS_STRIPE_CURRENCY', env('STRIPE_CURRENCY', 'usd')),
    ],
    'tinkoff' => [
        'currency' => env('PAYMENTS_TINKOFF_CURRENCY', 'rub'),
    ],
    'sber' => [
        'currency' => env('PAYMENTS_SBER_CURRENCY', 'rub'),
    ],
    'atol_default_email' => env('ATOL_DEFAULT_EMAIL', 'client@example.com'),
];
