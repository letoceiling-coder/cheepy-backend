<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhookLog extends Model
{
    protected $fillable = [
        'provider',
        'provider_event_id',
        'payload',
        'headers',
        'status',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
    ];
}
