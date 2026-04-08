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
        'ai_score',
        'legacy_score',
        'final_score',
        'boost_applied',
        'decision',
        'reason',
        'decision_reason',
        'algorithm_version',
        'created_at',
    ];

    protected $casts = [
        'confidence' => 'integer',
        'ai_score' => 'float',
        'legacy_score' => 'float',
        'final_score' => 'float',
        'boost_applied' => 'float',
        'created_at' => 'datetime',
        'decision' => AutoMappingDecision::class,
    ];

    public function donorCategory(): BelongsTo
    {
        return $this->belongsTo(DonorCategory::class, 'donor_category_id');
    }
}
