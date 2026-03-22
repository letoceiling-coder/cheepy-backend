<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links system_product to donor product (products.id).
 *
 * @property int $id
 * @property int $system_product_id
 * @property int $donor_product_id
 * @property string $source parser|...
 * @property \Carbon\CarbonImmutable|null $donor_updated_at
 */
class ProductSource extends Model
{
    public const SOURCE_PARSER = 'parser';

    protected $fillable = [
        'system_product_id',
        'donor_product_id',
        'source',
        'donor_updated_at',
    ];

    protected $casts = [
        'donor_updated_at' => 'datetime',
    ];

    public function systemProduct(): BelongsTo
    {
        return $this->belongsTo(SystemProduct::class);
    }

    public function donorProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'donor_product_id');
    }
}
