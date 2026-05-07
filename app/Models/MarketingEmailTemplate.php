<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingEmailTemplate extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'send_trigger',
        'subject',
        'body_html',
        'is_automatic',
        'is_active',
        'placeholder_hint',
    ];

    protected function casts(): array
    {
        return [
            'is_automatic' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(MarketingCampaign::class, 'marketing_email_template_id');
    }
}
