<?php

return [
    /** CA bundle for T-Bank / Sber / ATOL (Russian Trusted Root). */
    'ca_bundle' => env('PAYMENTS_CA_BUNDLE', storage_path('certs/russian-trusted-ca-bundle.pem')),

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
