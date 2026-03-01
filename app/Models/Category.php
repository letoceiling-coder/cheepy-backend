<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'external_slug', 'name', 'slug', 'url', 'parent_id', 'sort_order', 'icon',
        'enabled', 'linked_to_parser', 'parser_products_limit', 'parser_max_pages',
        'parser_depth_limit', 'products_count', 'subcategory_options_count', 'last_parsed_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'linked_to_parser' => 'boolean',
        'last_parsed_at' => 'datetime',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function filtersConfig(): HasMany
    {
        return $this->hasMany(FilterConfig::class);
    }

    public function excludedRules(): HasMany
    {
        return $this->hasMany(ExcludedRule::class);
    }
}
