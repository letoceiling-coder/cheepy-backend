<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemProduct extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    protected $table = 'system_products';

    protected $fillable = [
        'name',
        'description',
        'price',
        'price_raw',
        'status',
        'seller_id',
        'category_id',
        'brand_id',
        'list_position',
    ];

    protected $casts = [
        'price_raw' => 'integer',
        'list_position' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(\App\Models\CatalogCategory::class, 'category_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Seller::class, 'seller_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Brand::class, 'brand_id');
    }

    public function productSources(): HasMany
    {
        return $this->hasMany(\App\Models\ProductSource::class, 'system_product_id');
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(\App\Models\SystemProductAttribute::class, 'system_product_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(\App\Models\SystemProductPhoto::class, 'system_product_id')->orderBy('sort_order');
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }
}
