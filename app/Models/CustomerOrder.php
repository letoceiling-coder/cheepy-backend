<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerOrder extends Model
{
    protected $fillable = [
        'user_id', 'number', 'status', 'subtotal_amount', 'discount_amount', 'delivery_amount',
        'bonus_spent_amount', 'total_amount', 'currency', 'payment_status', 'delivery_provider',
        'delivery_type', 'delivery_snapshot', 'paid_at',
    ];

    protected $casts = [
        'delivery_snapshot' => 'array',
        'paid_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CustomerOrderItem::class, 'order_id');
    }
}
