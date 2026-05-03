<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerWalletLedger extends Model
{
    protected $table = 'customer_wallet_ledger';

    protected $fillable = [
        'user_id', 'amount', 'balance_after', 'currency', 'kind', 'source_type',
        'source_id', 'description', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
