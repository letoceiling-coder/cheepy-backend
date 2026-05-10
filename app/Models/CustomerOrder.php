<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerOrder extends Model
{
    protected $fillable = [
        'user_id',
        'coupon_id',
        'number',
        'status',
        'subtotal_amount',
        'discount_amount',
        'coupon_snapshot',
        'delivery_amount',
        'bonus_spent_amount',
        'total_amount',
        'currency',
        'payment_status',
        'delivery_provider',
        'delivery_type',
        'delivery_snapshot',
        'paid_at',
    ];

    protected $casts = [
        'delivery_snapshot' => 'array',
        'coupon_snapshot' => 'array',
        'paid_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CustomerOrderItem::class, 'order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'customer_order_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }
}
