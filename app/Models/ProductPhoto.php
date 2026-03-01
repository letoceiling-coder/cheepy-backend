<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPhoto extends Model
{
    protected $fillable = [
        'product_id', 'original_url', 'medium_url', 'local_path', 'local_medium_path',
        'cdn_url', 'hash', 'mime_type', 'file_size', 'width', 'height',
        'is_primary', 'sort_order', 'download_status',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Публичный URL для отображения — локальный или оригинальный
     */
    public function getPublicUrlAttribute(): string
    {
        if ($this->local_path && file_exists(storage_path('app/' . $this->local_path))) {
            return url('storage/' . $this->local_path);
        }
        return $this->original_url;
    }
}
