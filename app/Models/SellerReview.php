<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerReview extends Model
{
    protected $fillable = [
        'seller_id',
        'author_name',
        'rating',
        'body',
        'is_published',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'rating' => 'integer',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
