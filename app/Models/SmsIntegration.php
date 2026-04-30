<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsIntegration extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'config',
        'last_successful_auth_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'config' => 'array',
        'last_successful_auth_at' => 'datetime',
    ];
}
