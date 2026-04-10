<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmMediaFolder extends Model
{
    public const SLUG_TRASH = '__trash__';

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'is_system',
        'sort_order',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CrmMediaFile::class, 'folder_id');
    }

    public static function trashFolder(): ?self
    {
        return self::query()->where('slug', self::SLUG_TRASH)->first();
    }
}
