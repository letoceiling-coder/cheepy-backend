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
];
