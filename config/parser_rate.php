<?php

return [
    /*
     * Max requests per minute (throttling)
     */
    'max_requests_per_minute' => (int) env('PARSER_MAX_REQUESTS_PER_MINUTE', 60),

    /*
     * Delay between requests: min/max milliseconds
     */
    'delay_min_ms' => (int) env('PARSER_DELAY_MIN_MS', 500),
    'delay_max_ms' => (int) env('PARSER_DELAY_MAX_MS', 2000),

    /*
     * Retry configuration
     */
    'retry_count' => (int) env('PARSER_RETRY_COUNT', 3),
    'retry_backoff_seconds' => [2, 5, 10],

    /*
     * Block detection: HTTP codes that trigger block handling
     */
    'block_codes' => [403, 429],
];
