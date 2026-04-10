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
        'is_enabled',
        'media_file_id',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_enabled' => 'boolean',
    ];

    public function mediaFile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CrmMediaFile::class, 'media_file_id');
    }

    public function systemProduct(): BelongsTo
    {
        return $this->belongsTo(SystemProduct::class);
    }
}
