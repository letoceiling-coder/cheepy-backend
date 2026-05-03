<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralCode extends Model
{
    protected $fillable = ['user_id', 'code', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
