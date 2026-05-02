<?php

namespace App\Http\Resources;

use App\Models\SystemProduct;
use App\Services\Catalog\PublicSystemCatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Публичная карточка товара для витрины: цены уже с комиссией маркетплейса.
 *
 * @mixin SystemProduct
 */
class PublicSystemProductStorefrontCardResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var SystemProduct $model */
        $model = $this->resource;

        return app(PublicSystemCatalogService::class)->storefrontCard($model);
    }
}
