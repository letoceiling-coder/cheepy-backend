<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttribute extends Model
{
    protected $fillable = [
        'product_id',
        'category_id',
        'attribute_key',
        'attr_name',
        'attr_value',
        'attr_value_original',
        'attr_type',
        'confidence',
    ];

    protected $casts = [
        'confidence' => 'float',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
