<?php

return [
    'proxy_enabled' => (bool) env('PARSER_PROXY_ENABLED', true),
    'proxy' => env('PARSER_PROXY_URL', 'http://89.169.39.244:3128'),
    'proxy_url' => env('PARSER_PROXY_URL', 'http://89.169.39.244:3128'),
    'delay_min' => (int) env('PARSER_DELAY_MIN', 1500),
    'delay_max' => (int) env('PARSER_DELAY_MAX', 3000),
    'timeout' => (int) env('PARSER_TIMEOUT', 60),
    'workers_parser' => (int) env('PARSER_WORKERS_PARSER', 2),
    'workers_photos' => (int) env('PARSER_WORKERS_PHOTOS', 1),
    'queue_threshold' => (int) env('PARSER_QUEUE_THRESHOLD', 500),
    // Backward-compatible switch: "legacy" keeps existing DownloadPhotosJob flow.
    'photo_pipeline_mode' => env('PARSER_PHOTO_PIPELINE_MODE', 'legacy'),
    // Photo pipeline hardening limits (used only in micro mode).
    'max_photo_queue_size' => (int) env('PARSER_MAX_PHOTO_QUEUE_SIZE', 3000),
    'micro_product_batch_size' => (int) env('PARSER_MICRO_PRODUCT_BATCH_SIZE', 40),
    'micro_dispatch_rate_per_sec' => (int) env('PARSER_MICRO_DISPATCH_RATE_PER_SEC', 20),
    'micro_max_single_per_product' => (int) env('PARSER_MICRO_MAX_SINGLE_PER_PRODUCT', 24),
    'photo_job_timeout_seconds' => (int) env('PARSER_PHOTO_JOB_TIMEOUT_SECONDS', 30),
];
