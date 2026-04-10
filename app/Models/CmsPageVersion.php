<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CmsPageVersion extends Model
{
    protected $table = 'cms_page_versions';

    protected $fillable = [
        'cms_page_id',
        'version_number',
        'status',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(CmsPage::class, 'cms_page_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(CmsPageBlock::class, 'cms_page_version_id')->orderBy('sort_order');
    }
}
