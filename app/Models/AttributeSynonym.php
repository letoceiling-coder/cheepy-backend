<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttributeSynonym extends Model
{
    protected $fillable = ['attribute_key', 'word', 'normalized_value'];
}
