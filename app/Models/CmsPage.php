<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CmsPage extends Model
{
    protected $table = 'cms_pages';

    protected $fillable = [
        'page_key',
        'page_type',
        'path_prefix',
        'slug',
        'title',
        'is_active',
        'status',
        'published_version_id',
        'seo_title',
        'seo_description',
        'og_title',
        'og_description',
        'og_image_url',
        'canonical_url',
        'robots',
        'seo_extra',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'seo_extra' => 'array',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(CmsPageVersion::class, 'published_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CmsPageVersion::class, 'cms_page_id');
    }
}
