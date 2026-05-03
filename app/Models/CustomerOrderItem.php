<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerOrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'product_name', 'product_image', 'sku', 'quantity',
        'unit_price', 'total_price', 'attributes',
    ];

    protected $casts = [
        'attributes' => 'array',
    ];
}
