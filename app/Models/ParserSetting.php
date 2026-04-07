<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class ParserSetting extends Model
{
    protected $fillable = [
        'download_photos',
        'store_photo_links',
        'max_workers',
        'request_delay_min',
        'request_delay_max',
        'timeout_seconds',
        'workers_parser',
        'workers_photos',
        'proxy_enabled',
        'proxy_url',
        'queue_threshold',
        'default_max_pages',
        'default_products_per_category',
        'default_linked_only',
        'default_category_ids',
        'default_no_details',
    ];

    protected $casts = [
        'download_photos' => 'boolean',
        'store_photo_links' => 'boolean',
        'max_workers' => 'integer',
        'request_delay_min' => 'integer',
        'request_delay_max' => 'integer',
        'timeout_seconds' => 'integer',
        'workers_parser' => 'integer',
        'workers_photos' => 'integer',
        'proxy_enabled' => 'boolean',
        'queue_threshold' => 'integer',
        'default_max_pages' => 'integer',
        'default_products_per_category' => 'integer',
        'default_linked_only' => 'boolean',
        'default_category_ids' => 'array',
        'default_no_details' => 'boolean',
    ];

    public static function current(): self
    {
        if (!Schema::hasTable('parser_settings')) {
            return new self(self::defaults());
        }

        $row = self::first();
        if (!$row) {
            $row = self::create(self::defaults());
        }

        return $row;
    }

    public static function defaults(): array
    {
        return [
            'download_photos' => false,
            'store_photo_links' => true,
            'max_workers' => 3,
            'request_delay_min' => 1500,
            'request_delay_max' => 3000,
            'timeout_seconds' => 60,
            'workers_parser' => 2,
            'workers_photos' => 1,
            'proxy_enabled' => true,
            'proxy_url' => 'http://89.169.39.244:3128',
            'queue_threshold' => 150,
            'default_max_pages' => 0,
            'default_products_per_category' => 0,
            'default_linked_only' => false,
            'default_category_ids' => [],
            'default_no_details' => false,
        ];
    }
}
