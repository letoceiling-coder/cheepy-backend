<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
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
}
