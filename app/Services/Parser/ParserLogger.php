<?php

namespace App\Services\Parser;

use App\Models\ParserLog;

class ParserLogger
{
    public static function write(
        string $type,
        string $message,
        array $context = [],
        ?int $jobId = null,
        ?string $source = 'Parser'
    ): void {
        $level = match ($type) {
            'error', 'network_error', 'parsing_error' => 'error',
            'warning' => 'warning',
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
