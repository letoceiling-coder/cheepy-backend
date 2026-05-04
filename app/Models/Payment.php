<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'api_key_id',
        'customer_order_id',
        'amount',
        'refunded_amount',
        'provider',
        'status',
        'provider_id',
        'provider_event_id',
        'atol_uuid',
        'atol_status',
        'user_email',
        'return_token',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'refunded_amount' => 'decimal:4',
        'updated_at' => 'datetime',
    ];

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(SaasApiKey::class, 'api_key_id');
    }

    public function customerOrder(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }
}
