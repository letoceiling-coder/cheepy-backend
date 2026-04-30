<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialOauthIntegration extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'config',
        'last_successful_oauth_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'config' => 'array',
        'last_successful_oauth_at' => 'datetime',
    ];
}
