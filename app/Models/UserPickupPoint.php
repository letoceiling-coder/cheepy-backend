<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPickupPoint extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'office_code', 'name', 'city', 'address',
        'lat', 'lng', 'work_time', 'is_default', 'provider_payload',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'is_default' => 'boolean',
        'provider_payload' => 'array',
    ];
}
