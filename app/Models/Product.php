<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'external_id', 'source_url', 'title', 'price', 'price_raw', 'description',
        'category_id', 'seller_id', 'brand_id', 'category_slugs', 'color', 'size_range',
        'characteristics', 'source_link', 'source_published_at', 'color_external_id',
        'status', 'is_relevant', 'relevance_checked_at', 'parse_error',
        'photos', 'photos_downloaded', 'photos_count', 'parsed_at',
    ];

    protected $casts = [
        'category_slugs' => 'array',
        'characteristics' => 'array',
        'photos' => 'array',
        'photos_downloaded' => 'boolean',
        'is_relevant' => 'boolean',
        'source_published_at' => 'datetime',
        'relevance_checked_at' => 'datetime',
        'parsed_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** Связь с таблицей product_photos (не путать с $this->photos — это JSON-колонка с URL) */
    public function photoRecords(): HasMany
    {
        return $this->hasMany(ProductPhoto::class)->orderBy('sort_order');
    }

    public function primaryPhotoRecord(): ?ProductPhoto
    {
        return $this->hasMany(ProductPhoto::class)->where('is_primary', true)->first();
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }

    /**
     * Извлечь целочисленную цену из строки "900 ₽" или "900р" или "900"
     */
    public static function parsePriceRaw(?string $price): ?int
    {
        if (!$price) return null;
        if (preg_match('/(\d[\d\s]*)\s*[₽рруб]/u', $price, $m)) {
            return (int) preg_replace('/\s/', '', $m[1]);
        }
        if (preg_match('/(\d+)/', $price, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Создать или обновить продукт по данным парсера
     */
    public static function upsertFromParser(array $data, ?int $categoryId = null, ?int $sellerId = null): static
    {
        $externalId = (string) ($data['id'] ?? '');

        $attrs = [
            'title' => $data['title'] ?? '',
            'price' => $data['price'] ?? null,
            'price_raw' => static::parsePriceRaw($data['price'] ?? null),
            'description' => $data['description'] ?? null,
            'category_id' => $categoryId,
            'seller_id' => $sellerId,
            'category_slugs' => $data['category_slugs'] ?? [],
            'characteristics' => $data['characteristics'] ?? [],
            'photos' => $data['photos'] ?? [],
            'photos_count' => count($data['photos'] ?? []),
            'status' => 'active',
            'parsed_at' => now(),
        ];

        // Извлекаем цвет из characteristics — только короткие значения (не мусор)
        $chars = $data['characteristics'] ?? [];
        if (is_array($chars)) {
            $rawColor = $chars['color'] ?? $chars['Цвет'] ?? null;
            $rawSize  = $chars['size'] ?? $chars['Размер'] ?? $chars['size_range'] ?? null;
            // Мусорные значения характеристик содержат "Добавить в корзину" или длиннее 200 символов
            $attrs['color'] = (is_string($rawColor) && mb_strlen($rawColor) <= 200 && !str_contains($rawColor, 'Добавить'))
                ? mb_substr($rawColor, 0, 490) : null;
            $attrs['size_range'] = (is_string($rawSize) && mb_strlen($rawSize) <= 200 && !str_contains($rawSize, 'Добавить'))
                ? mb_substr($rawSize, 0, 490) : null;
        }

        // Источник
        $desc = $data['description'] ?? '';
        if (preg_match('#(vk\.com\S+)#', $desc, $m)) {
            $attrs['source_link'] = $m[1];
        }
        if (preg_match('/Товар добавлен:\s*(\d{2}\.\d{2}\.\d{4}\s*\d{2}:\d{2}:\d{2})/u', $desc, $m)) {
            try {
                $attrs['source_published_at'] = \Carbon\Carbon::createFromFormat('d.m.Y H:i:s', trim($m[1]));
            } catch (\Throwable $e) {}
        }

        // source_url
        $attrs['source_url'] = config('sadovod.base_url', 'https://sadovodbaza.ru') . '/odejda/' . $externalId;

        return static::updateOrCreate(['external_id' => $externalId], $attrs);
    }
}
