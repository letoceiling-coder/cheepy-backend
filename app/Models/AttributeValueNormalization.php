<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttributeValueNormalization extends Model
{
    protected $table = 'attribute_value_normalization';

    protected $fillable = ['attribute_key', 'raw_value', 'normalized_value'];
}
