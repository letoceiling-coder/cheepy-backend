<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSource extends Model
{
    protected $table = 'product_sources';

    protected $fillable = [
        'system_product_id',
        'product_id',
    ];

    public function systemProduct(): BelongsTo
    {
        return $this->belongsTo(SystemProduct::class, 'system_product_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
