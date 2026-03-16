<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DonorCategory extends Model
{
    protected $table = 'donor_categories';

    protected $fillable = [
        'external_id',
        'name',
        'slug',
        'parent_id',
        'source_url',
        'parser_enabled',
    ];

    protected $casts = [
        'parser_enabled' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(DonorCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(DonorCategory::class, 'parent_id');
    }

    public function mapping(): HasOne
    {
        return $this->hasOne(CategoryMapping::class, 'donor_category_id');
    }

    /**
     * Catalog categories linked to this donor category (many-to-many via category_mapping).
     */
    public function catalogCategories(): BelongsToMany
    {
        return $this->belongsToMany(
            CatalogCategory::class,
            'category_mapping',
            'donor_category_id',
            'catalog_category_id'
        );
    }
}
