<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSource extends Model
{
    /** Связь system_products с донором `products` (парсер). */
    public const SOURCE_PARSER = 'parser';

    protected $table = 'product_sources';

    protected $fillable = [
        'system_product_id',
        'donor_product_id',
        'source',
        'donor_updated_at',
    ];

    protected $casts = [
        'donor_updated_at' => 'datetime',
    ];

    public function systemProduct(): BelongsTo
    {
        return $this->belongsTo(SystemProduct::class, 'system_product_id');
    }

    /** Донорный товар парсера (`products.id`). */
    public function donorProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'donor_product_id');
    }

    /**
     * @deprecated Используйте donorProduct(); имя колонки в БД — donor_product_id.
     */
    public function product(): BelongsTo
    {
        return $this->donorProduct();
    }
}
