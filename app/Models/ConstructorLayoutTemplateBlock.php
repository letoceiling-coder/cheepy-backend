<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConstructorLayoutTemplateBlock extends Model
{
    protected $fillable = [
        'constructor_layout_template_id',
        'sort_order',
        'block_type',
        'settings',
        'client_key',
        'is_visible',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_visible' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ConstructorLayoutTemplate::class, 'constructor_layout_template_id');
    }
}
