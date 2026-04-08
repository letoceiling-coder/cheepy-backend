<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogCategory extends Model
{
    protected $table = 'catalog_categories';

    protected $fillable = [
        'name',
        'slug',
        'parent_id',
        'sort_order',
        'icon',
        'is_active',
        'embedding',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'embedding' => 'array',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(CatalogCategory::class, 'parent_id')->orderBy('sort_order');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(CategoryMapping::class, 'catalog_category_id');
    }

    /**
     * Donor categories linked to this catalog category (many-to-many via category_mapping).
     */
    public function donorCategories(): BelongsToMany
    {
        return $this->belongsToMany(
            DonorCategory::class,
            'category_mapping',
            'catalog_category_id',
            'donor_category_id'
        );
    }
}
