<?php

namespace App\Support;

final class AutoMappingCommandContext
{
    public int $skippedDuplicateLogs = 0;

    public int $reprocessedCount = 0;

    public int $logsWrittenCount = 0;

    public function __construct(
        public readonly bool $force = false,
    ) {}
}
