<?php

namespace App\Services\Catalog;

/**
 * Shared auto-mapping algorithm metadata (versioning, duplicate detection).
 */
final class AutoMappingConfig
{
    public const V1 = 'v1';
    public const V2 = 'v2';
    public const VERSION = self::V1;

    /** Confidence delta at or above this vs last log → not a duplicate. */
    public const CONFIDENCE_SIGNIFICANT_DELTA = 10;
}
