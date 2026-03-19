<?php

namespace App\Models;

use App\Enums\AutoMappingDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoMappingLog extends Model
{
    public $timestamps = false;

    protected $table = 'auto_mapping_logs';

    protected $fillable = [
        'donor_category_id',
        'suggested_catalog_category_id',
        'confidence',
        'decision',
        'reason',
        'algorithm_version',
        'created_at',
    ];

    protected $casts = [
        'confidence' => 'integer',
        'created_at' => 'datetime',
        'decision' => AutoMappingDecision::class,
    ];

    public function donorCategory(): BelongsTo
    {
        return $this->belongsTo(DonorCategory::class, 'donor_category_id');
    }
}
