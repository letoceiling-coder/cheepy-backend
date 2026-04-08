<?php

namespace App\Services\Parser;

use App\Models\ParserLog;
use App\Models\ParserState;

class ParserLogger
{
    /**
     * Global gate: no parser_logs while parser is not RUNNING (ParserLog::write enforces the same for direct callers).
     */
    public static function write(
        string $type,
        string $message,
        array $context = [],
        ?int $jobId = null,
        ?string $source = 'Parser'
    ): void {
        try {
            if (!ParserState::current()->isRunning()) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        $level = match ($type) {
            'error', 'network_error', 'parsing_error', 'database_error' => 'error',
            'warning', 'timeout' => 'warning',
            default => 'info',
        };

        ParserLog::write(
            $level,
            $message,
            $context,
            $jobId,
            $source,
            $type,
            null,
            $context['url'] ?? null,
            isset($context['product_id']) ? (int) $context['product_id'] : null,
            isset($context['attempt']) ? (int) $context['attempt'] : null
        );
    }
}
