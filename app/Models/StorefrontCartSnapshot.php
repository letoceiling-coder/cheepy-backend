<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontCartSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'items',
        'last_abandon_email_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'last_abandon_email_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
