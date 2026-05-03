<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerReceipt extends Model
{
    protected $fillable = ['user_id', 'order_id', 'number', 'amount', 'currency', 'fiscal_url', 'issued_at'];

    protected $casts = [
        'issued_at' => 'datetime',
    ];
}
