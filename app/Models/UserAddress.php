<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAddress extends Model
{
    protected $fillable = [
        'user_id', 'label', 'country', 'region', 'city', 'postal_code', 'line1', 'line2',
        'lat', 'lng', 'source', 'is_default', 'provider_payload',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'is_default' => 'boolean',
        'provider_payload' => 'array',
    ];
}
