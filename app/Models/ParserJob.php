<?php

namespace App\Models;

use App\Support\ParserJobOptions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ParserJob extends Model
{
    use HasFactory;

    protected $table = 'parser_jobs';

    protected $fillable = [
        'type',
        'options',
        'status',
        'progress',
        'parsed_categories',
        'total_categories',
        'parsed_products',
        'total_products',
        'saved_products',
        'errors_count',
        'photos_downloaded',
        'photos_failed',
        'progress_percent',
        'current_action',
        'current_page',
        'total_pages',
        'current_category_slug',
        'pid',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected $casts = [
        'options' => 'array',
        'progress' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $job) {
            $opts = $job->options;
            if (is_string($opts)) {
                $decoded = json_decode($opts, true);
                $opts = is_array($decoded) ? $decoded : [];
            }
            if (! is_array($opts)) {
                $opts = [];
            }

            if (empty($opts)) {
                Log::critical('OPTIONS EMPTY DETECTED', [
                    'trace' => array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10), 0, 10),
                ]);
                throw new RuntimeException('CRITICAL: OPTIONS EMPTY - BLOCKED');
            }

            ParserJobOptions::assertCategoriesForJob((string) ($job->type ?? 'full'), $opts);

            $job->options = $opts;
        });
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ParserLog::class, 'job_id');
    }
}
