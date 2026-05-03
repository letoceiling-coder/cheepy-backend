<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralEvent extends Model
{
    protected $fillable = [
        'referrer_user_id', 'referred_user_id', 'referral_code_id', 'event_type',
        'reward_amount', 'reward_granted_at',
    ];

    protected $casts = [
        'reward_granted_at' => 'datetime',
    ];
}
