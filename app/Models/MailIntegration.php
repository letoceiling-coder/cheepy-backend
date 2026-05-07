<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailIntegration extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'config',
        'last_successful_send_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'config' => 'array',
            'last_successful_send_at' => 'datetime',
        ];
    }
}
