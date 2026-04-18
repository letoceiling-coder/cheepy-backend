<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Связь донорного товара с похожими (другой цвет / вариант на витрине донора).
 *
 * @property int $id
 * @property int $product_id
 * @property string $related_external_id
 * @property int|null $related_product_id
 * @property int $sort_order
 */
class ProductSimilar extends Model
{
    protected $table = 'product_similar';

    protected $fillable = [
        'product_id',
        'related_external_id',
        'related_product_id',
        'sort_order',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }
}
