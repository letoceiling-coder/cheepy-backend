<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiTokenUsageLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'provider',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'cost_usd',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'cost_usd' => 'decimal:6',
        'meta' => 'array',
        'created_at' => 'datetime',
    ];
}
