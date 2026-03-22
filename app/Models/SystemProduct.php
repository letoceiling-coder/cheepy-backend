<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * System product — flexible marketplace core for filters, CRM, SaaS.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $price
 * @property int|null $price_raw
 * @property string $status
 * @property int|null $seller_id
 * @property int|null $category_id catalog_categories
 * @property int|null $brand_id
 */
class SystemProduct extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    protected $fillable = [
        'name',
        'description',
        'price',
        'price_raw',
        'status',
        'seller_id',
        'category_id',
        'brand_id',
    ];

    protected $casts = [
        'price_raw' => 'integer',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(SystemProductAttribute::class)->orderBy('attr_name');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(SystemProductPhoto::class)->orderBy('sort_order');
    }

    public function productSources(): HasMany
    {
        return $this->hasMany(ProductSource::class);
    }

    /** Donor products (products table) linked via product_sources. */
    public function donorProducts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'product_sources',
            'system_product_id',
            'donor_product_id'
        )->withPivot(['source'])->withTimestamps();
    }
}
