<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaign extends Model
{
    protected $fillable = [
        'name',
        'channel_key',
        'audience',
        'status',
        'subject',
        'body_html',
        'marketing_email_template_id',
        'scheduled_at',
        'metrics',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'metrics' => 'array',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MarketingEmailTemplate::class, 'marketing_email_template_id');
    }
}
