<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttributeRule extends Model
{
    protected $fillable = [
        'attribute_key', 'display_name', 'rule_type', 'pattern',
        'apply_synonyms', 'attr_type', 'priority', 'enabled',
    ];

    protected $casts = [
        'apply_synonyms' => 'boolean',
        'enabled'        => 'boolean',
        'priority'       => 'integer',
    ];
}
