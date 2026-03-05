<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParserJob extends Model
{
    protected $fillable = [
        'type', 'options', 'status',
        'total_categories', 'parsed_categories',
        'total_products', 'parsed_products', 'saved_products', 'errors_count',
        'photos_downloaded', 'photos_failed',
        'current_action', 'current_page', 'total_pages', 'current_category_slug',
        'pid', 'log_file', 'started_at', 'finished_at', 'error_message',
    ];

    protected $casts = [
        'options' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(ParserLog::class, 'job_id');
    }

    public function getProgressPercentAttribute(): int
    {
        if ($this->total_products <= 0) {
            if ($this->total_categories <= 0) return 0;
            return (int) ($this->parsed_categories / $this->total_categories * 100);
        }
        return (int) ($this->parsed_products / $this->total_products * 100);
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled']);
    }

    /**
     * Format job for broadcasting (matches ParserController format)
     */
    public function formatForBroadcast(bool $withLogs = false): array
    {
        $data = [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'options' => $this->options,
            'progress' => [
                'categories' => ['done' => $this->parsed_categories, 'total' => $this->total_categories],
                'products' => ['done' => $this->parsed_products, 'total' => $this->total_products],
                'saved' => $this->saved_products,
                'errors' => $this->errors_count,
                'photos' => ['downloaded' => $this->photos_downloaded, 'failed' => $this->photos_failed],
                'percent' => $this->progress_percent,
                'current_action' => $this->current_action,
                'current_page' => $this->current_page,
                'total_pages' => $this->total_pages,
                'current_category' => $this->current_category_slug,
            ],
            'pid' => $this->pid,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'error_message' => $this->error_message,
            'created_at' => $this->created_at->toIso8601String(),
        ];

        if ($withLogs) {
            $data['logs'] = $this->logs()
                ->latest('logged_at')
                ->limit(100)
                ->get(['level', 'module', 'message', 'context', 'logged_at'])
                ->toArray();
        }

        return $data;
    }
}
