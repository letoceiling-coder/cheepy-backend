<?php

return [
    /** CA bundle for T-Bank / Sber / ATOL (Russian Trusted Root). */
    'ca_bundle' => env('PAYMENTS_CA_BUNDLE', storage_path('certs/russian-trusted-ca-bundle.pem')),

    /** Email в чеке T‑Bank, если у покупателя нет email (обязателен Email или Phone). */
    'receipt_fallback_email' => env('PAYMENTS_RECEIPT_FALLBACK_EMAIL', 'noreply@cheepy.shop'),

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
