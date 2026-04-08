<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemProduct extends Model
{
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
    ];

    protected $casts = [
        'price_raw' => 'integer',
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
}
