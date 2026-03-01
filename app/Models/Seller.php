<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Seller extends Model
{
    protected $fillable = [
        'slug', 'name', 'source_url', 'pavilion', 'pavilion_line', 'pavilion_number',
        'description', 'phone', 'whatsapp_url', 'whatsapp_number', 'telegram_url', 'vk_url',
        'external_shop_id', 'status', 'is_verified', 'rating', 'products_count',
        'seller_categories', 'last_parsed_at',
    ];

    protected $casts = [
        'seller_categories' => 'array',
        'is_verified' => 'boolean',
        'rating' => 'decimal:2',
        'last_parsed_at' => 'datetime',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Извлечь shop ID из whatsapp_url: /posts/link?utm_content=shop{id}&...
     */
    public function getShopIdFromUrl(string $url): ?string
    {
        if (preg_match('/utm_content=shop(\d+)/', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}
