<?php

/**
 * Cheepy CRM / публичный каталог. Значения читаются через config(), чтобы работало при php artisan config:cache.
 */
return [
    'public_use_system_products' => env('PUBLIC_CATALOG_USE_SYSTEM_PRODUCTS', true),
    'auto_ingest_from_parser' => env('CATALOG_AUTO_INGEST_FROM_PARSER', true),
];
