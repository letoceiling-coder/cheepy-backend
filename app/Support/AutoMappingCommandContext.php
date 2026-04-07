<?php

namespace App\Support;

/**
 * Bound only during catalog:auto-map-categories --sync to collect per-run counters.
 */
final class AutoMappingCommandContext
{
    public int $skippedDuplicateLogs = 0;

    /** Every AutoMappingService::process() invocation. */
    public int $reprocessedCount = 0;

    /** Successful auto_mapping_logs inserts this run. */
    public int $logsWrittenCount = 0;

    public function __construct(
        public readonly bool $force = false,
    ) {}
}
