<?php

return [
    /*
     * Max requests per minute (throttling). Ignored if max_requests_per_second is set.
     */
    'max_requests_per_minute' => (int) env('PARSER_MAX_REQUESTS_PER_MINUTE', 300),

    /*
     * Max requests per second (rate limit protection, prevents donor blocking)
     */
    'max_requests_per_second' => (int) env('PARSER_MAX_REQUESTS_PER_SECOND', 5),

    /*
     * Delay between requests: min/max milliseconds
     */
    'delay_min_ms' => (int) env('PARSER_DELAY_MIN_MS', 200),
    'delay_max_ms' => (int) env('PARSER_DELAY_MAX_MS', 500),

    /*
     * Retry configuration (3 retries, exponential backoff)
     */
    'retry_count' => (int) env('PARSER_RETRY_COUNT', 3),
    'retry_backoff_seconds' => [2, 5, 10],

    /*
     * Block detection: HTTP codes that trigger block handling
     */
    'block_codes' => [403, 429],
];
