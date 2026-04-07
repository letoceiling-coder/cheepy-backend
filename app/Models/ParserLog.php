<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParserLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'job_id',
        'level',
        'type',
        'module',
        'message',
        'url',
        'product_id',
        'attempt',
        'context',
        'entity_type',
        'entity_id',
        'logged_at',
    ];

    protected $casts = [
        'context' => 'array',
        'logged_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(ParserJob::class);
    }

    public static function write(
        string $level,
        string $message,
        array $context = [],
        ?int $jobId = null,
        string $module = 'Parser',
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $url = null,
        ?int $productId = null,
        ?int $attempt = null
    ): void
    {
        try {
            if (!ParserState::current()->isRunning()) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        static::create([
            'job_id' => $jobId,
            'level' => $level,
            'type' => $level,
            'module' => $module,
            'message' => mb_substr($message, 0, 995),
            'url' => $url ? mb_substr($url, 0, 995) : null,
            'product_id' => $productId,
            'attempt' => $attempt,
            'context' => $context ?: null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'logged_at' => now(),
        ]);
    }
}
