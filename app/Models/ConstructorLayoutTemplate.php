<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConstructorLayoutTemplate extends Model
{
    protected $fillable = [
        'template_key',
        'name',
        'description',
        'is_system',
        'sort_order',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function blocks(): HasMany
    {
        return $this->hasMany(ConstructorLayoutTemplateBlock::class)
            ->orderBy('sort_order');
    }
}
