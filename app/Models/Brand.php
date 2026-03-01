<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    protected $fillable = [
        'name', 'slug', 'logo_url', 'logo_local_path', 'status',
        'seo_title', 'seo_description', 'category_ids',
    ];

    protected $casts = [
        'category_ids' => 'array',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
