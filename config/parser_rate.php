<?php

return [
    /*
     * Max requests per minute (throttling). Ignored when max_requests_per_second is set.
     */
    'max_requests_per_minute' => (int) env('PARSER_MAX_REQUESTS_PER_MINUTE', 720),

    /*
     * Max requests per second per worker (12 rps × 12 workers = 144 rps total).
     * Keep at 12 to maximise throughput without triggering donor rate limits.
     */
    'max_requests_per_second' => (int) env('PARSER_MAX_REQUESTS_PER_SECOND', 12),

    /*
     * Delay between requests: min/max milliseconds
     */
    'delay_min_ms' => (int) env('PARSER_DELAY_MIN_MS', 100),
    'delay_max_ms' => (int) env('PARSER_DELAY_MAX_MS', 250),

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
