<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerProfile extends Model
{
    protected $fillable = ['user_id', 'birthday', 'marketing_opt_in', 'preferences'];

    protected $casts = [
        'birthday' => 'date',
        'marketing_opt_in' => 'boolean',
        'preferences' => 'array',
    ];
}
