<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingNews extends Model
{
    protected $table = 'marketing_news';

    protected $fillable = [
        'slug', 'title', 'body',
        'image_url', 'video_url', 'file_url', 'file_label',
        'is_active', 'sort_order', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }
}
