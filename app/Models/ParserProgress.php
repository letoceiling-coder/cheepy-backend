<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParserProgress extends Model
{
    protected $fillable = [
        'job_id',
        'total_items',
        'processed_items',
        'failed_items',
        'current_url',
        'speed_per_min',
    ];

    protected $casts = [
        'job_id' => 'integer',
        'total_items' => 'integer',
        'processed_items' => 'integer',
        'failed_items' => 'integer',
        'speed_per_min' => 'float',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(ParserJob::class, 'job_id');
    }
}
