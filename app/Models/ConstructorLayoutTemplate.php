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
        'template_type',
        'page_scope',
        'page_key',
        'is_system',
        'is_editable',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_editable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function blocks(): HasMany
    {
        return $this->hasMany(ConstructorLayoutTemplateBlock::class)
            ->orderBy('sort_order');
    }
}
