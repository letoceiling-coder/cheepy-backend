<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Один блок на странице: тип из конструктора + произвольные settings (JSON).
 * Переиспользование: тот же block_type на разных страницах с разными settings.
 */
class CmsPageBlock extends Model
{
    protected $table = 'cms_page_blocks';

    protected $fillable = [
        'cms_page_version_id',
        'block_type',
        'sort_order',
        'settings',
        'client_key',
        'is_visible',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'is_visible' => true,
        'sort_order' => 0,
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(CmsPageVersion::class, 'cms_page_version_id');
    }
}
