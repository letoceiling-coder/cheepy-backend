<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'discount_type', 'discount_value', 'min_order_amount',
        'max_uses', 'max_uses_per_user', 'used_count', 'target', 'is_active',
        'starts_at', 'expires_at', 'rules',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'rules' => 'array',
    ];
}
