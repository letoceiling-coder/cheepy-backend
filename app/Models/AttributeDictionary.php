<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttributeDictionary extends Model
{
    protected $table = 'attribute_dictionary';

    protected $fillable = ['attribute_key', 'value', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];
}
