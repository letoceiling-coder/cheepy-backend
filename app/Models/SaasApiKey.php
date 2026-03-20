<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaasApiKey extends Model
{
    protected $fillable = [
        'name',
        'api_key_hash',
        'requests_per_minute',
        'balance',
        'cost_per_request',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requests_per_minute' => 'integer',
        'balance' => 'decimal:4',
        'cost_per_request' => 'decimal:6',
        'last_used_at' => 'datetime',
    ];

    public static function hashKey(string $apiKey): string
    {
        return hash('sha256', trim($apiKey));
    }
}
