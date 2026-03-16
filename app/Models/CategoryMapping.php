<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryMapping extends Model
{
    protected $table = 'category_mapping';

    protected $fillable = [
        'donor_category_id',
        'catalog_category_id',
        'confidence',
        'is_manual',
    ];

    protected $casts = [
        'is_manual' => 'boolean',
        'confidence' => 'integer',
    ];

    public function donorCategory(): BelongsTo
    {
        return $this->belongsTo(DonorCategory::class, 'donor_category_id');
    }

    public function catalogCategory(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'catalog_category_id');
    }
}
