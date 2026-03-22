<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relational attribute for system product. Ready for filters.
 * attr_value = display. value_int/value_float = typed for queries.
 */
class SystemProductAttribute extends Model
{
    public const TYPE_TEXT = 'text';
    public const TYPE_INT = 'int';
    public const TYPE_FLOAT = 'float';

    protected $fillable = [
        'system_product_id',
        'attr_name',
        'attr_value',
        'attr_value_original',
        'attr_type',
        'value_int',
        'value_float',
    ];

    protected $casts = [
        'value_int' => 'integer',
        'value_float' => 'float',
    ];

    public function systemProduct(): BelongsTo
    {
        return $this->belongsTo(SystemProduct::class);
    }
}
