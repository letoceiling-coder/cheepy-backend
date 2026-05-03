<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralLinkClick extends Model
{
    protected $fillable = ['referral_code_id', 'visitor_hash', 'ip_hash', 'user_agent', 'clicked_at'];

    protected $casts = [
        'clicked_at' => 'datetime',
    ];
}
