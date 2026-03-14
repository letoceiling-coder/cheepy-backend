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
    ];

    protected $casts = [
        'download_photos' => 'boolean',
        'store_photo_links' => 'boolean',
        'max_workers' => 'integer',
        'request_delay_min' => 'integer',
        'request_delay_max' => 'integer',
        'timeout_seconds' => 'integer',
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
            'download_photos' => true,
            'store_photo_links' => true,
            'max_workers' => 3,
            'request_delay_min' => 1500,
            'request_delay_max' => 3000,
            'timeout_seconds' => 60,
        ];
    }
}
