<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPaymentMethod extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'method_type', 'provider_token_encrypted', 'brand',
        'last4', 'exp_month', 'exp_year', 'is_default', 'is_active', 'meta',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];
}
