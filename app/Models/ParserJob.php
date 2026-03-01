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
}
