<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Photo for system product.
 */
class SystemProductPhoto extends Model
{
    protected $fillable = [
        'system_product_id',
        'url',
        'is_primary',
        'sort_order',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function systemProduct(): BelongsTo
    {
        return $this->belongsTo(SystemProduct::class);
    }
}
