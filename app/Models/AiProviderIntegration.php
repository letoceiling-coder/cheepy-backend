<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProviderIntegration extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'config',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'config' => 'array',
    ];
}
