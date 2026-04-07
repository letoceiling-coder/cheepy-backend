<?php

return [
    'request_delay_min' => env('PARSER_REQUEST_DELAY_MIN', 400),
    'request_delay_max' => env('PARSER_REQUEST_DELAY_MAX', 800),
    'user_agents' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ],
    'error_rate_threshold' => env('PARSER_ERROR_RATE_THRESHOLD', 0.1),
    'pause_duration_sec' => env('PARSER_PAUSE_DURATION_SEC', 60),
    'max_retries' => env('PARSER_HTTP_MAX_RETRIES', 3),
];
