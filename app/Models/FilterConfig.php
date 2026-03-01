<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FilterConfig extends Model
{
    protected $table = 'filters_config';

    protected $fillable = [
        'category_id', 'attr_name', 'display_name', 'display_type',
        'sort_order', 'range_min', 'range_max', 'preset_values',
        'is_active', 'is_filterable',
    ];

    protected $casts = [
        'preset_values' => 'array',
        'is_active' => 'boolean',
        'is_filterable' => 'boolean',
        'range_min' => 'decimal:2',
        'range_max' => 'decimal:2',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
