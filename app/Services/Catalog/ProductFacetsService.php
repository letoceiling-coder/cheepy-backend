<?php

namespace App\Services\Catalog;

use App\Models\SystemProduct;
use App\Models\SystemProductAttribute;
use App\Services\Catalog\ProductCache;
use Illuminate\Support\Facades\DB;

class ProductFacetsService
{
    private const CACHE_TTL = 60;

    private function cacheKey(?int $categoryId, ?float $priceMin, ?float $priceMax, array $activeAttributes = [], ?string $search = null): string
    {
        $payload = array_filter([
            'c' => $categoryId,
            'pmin' => $priceMin,
            'pmax' => $priceMax,
            'attrs' => $activeAttributes,
            'q' => $search !== null ? trim($search) : null,
        ], fn ($v) => $v !== null);
        return 'product_facets:' . hash('sha256', json_encode($payload));
    }

    /**
     * @param  int|null  $categoryId
     * @param  float|null  $priceMin
     * @param  float|null  $priceMax
     * @return array{attributes: array<string, array<int, array{value: string|int|float, count: int}>>, price: array{min: int|null, max: int|null}}
     */
    public function getFacets(
        ?int $categoryId,
        ?float $priceMin = null,
        ?float $priceMax = null,
        array $activeAttributes = [],
        ?string $search = null
    ): array
    {
        $key = $this->cacheKey($categoryId, $priceMin, $priceMax, $activeAttributes, $search);
        $tags = $categoryId !== null ? ['category_' . $categoryId] : [];

        return ProductCache::remember($key, self::CACHE_TTL, function () use ($categoryId, $priceMin, $priceMax, $activeAttributes, $search) {
        $priceBase = SystemProduct::query()
            ->where('status', SystemProduct::STATUS_PUBLISHED)
            ->when($categoryId !== null, fn ($q) => $q->where('category_id', $categoryId))
            ->when($priceMin !== null, fn ($q) => $q->where('price_raw', '>=', (int) $priceMin))
            ->when($priceMax !== null, fn ($q) => $q->where('price_raw', '<=', (int) $priceMax))
            ->when($search !== null && trim($search) !== '', fn ($q) => $q->where('name', 'like', '%' . trim($search) . '%'));

        foreach ($activeAttributes as $attrName => $attrValue) {
            $nameNorm = strtolower(trim((string) $attrName));
            $valueNorm = is_string($attrValue) ? strtolower(trim($attrValue)) : $attrValue;
            $valInt = is_int($attrValue) || (is_string($attrValue) && preg_match('/^-?\d+$/', trim((string) $attrValue)))
                ? (int) $attrValue : null;
            $valFloat = is_float($attrValue) || (is_string($attrValue) && preg_match('/^-?\d+(?:[.,]\d+)?/', trim((string) $attrValue)))
                ? (float) str_replace(',', '.', (string) $attrValue) : null;

            $priceBase->whereExists(function ($sub) use ($nameNorm, $valueNorm, $valInt, $valFloat) {
                $sub->selectRaw('1')
                    ->from('system_product_attributes as spa')
                    ->whereColumn('spa.system_product_id', 'system_products.id')
                    ->where('spa.attr_name', $nameNorm);

                if ($valInt !== null) {
                    $sub->where('spa.value_int', $valInt);
                } elseif ($valFloat !== null) {
                    $sub->where('spa.value_float', $valFloat);
                } else {
                    $sub->where('spa.attr_value', $valueNorm);
                }
            });
        }

        $priceRange = (clone $priceBase)
            ->selectRaw('MIN(price_raw) as min_price, MAX(price_raw) as max_price')
            ->first();

        $attrQuery = SystemProductAttribute::query()
            ->join('system_products as sp', 'sp.id', '=', 'system_product_attributes.system_product_id')
            ->where('sp.status', SystemProduct::STATUS_PUBLISHED)
            ->when($categoryId !== null, fn ($q) => $q->where('sp.category_id', $categoryId))
            ->when($priceMin !== null, fn ($q) => $q->where('sp.price_raw', '>=', (int) $priceMin))
            ->when($priceMax !== null, fn ($q) => $q->where('sp.price_raw', '<=', (int) $priceMax))
            ->when($search !== null && trim($search) !== '', fn ($q) => $q->where('sp.name', 'like', '%' . trim($search) . '%'))
            ->select([
                'system_product_attributes.attr_name',
                'system_product_attributes.attr_value',
                'system_product_attributes.attr_type',
                'system_product_attributes.value_int',
                'system_product_attributes.value_float',
                DB::raw('COUNT(DISTINCT system_product_attributes.system_product_id) as product_count'),
            ])
            ->groupBy(
                'system_product_attributes.attr_name',
                'system_product_attributes.attr_value',
                'system_product_attributes.attr_type',
                'system_product_attributes.value_int',
                'system_product_attributes.value_float'
            )
            ->orderByRaw('product_count DESC');

        foreach ($activeAttributes as $attrName => $attrValue) {
            $nameNorm = strtolower(trim((string) $attrName));
            $valueNorm = is_string($attrValue) ? strtolower(trim($attrValue)) : $attrValue;
            $valInt = is_int($attrValue) || (is_string($attrValue) && preg_match('/^-?\d+$/', trim((string) $attrValue)))
                ? (int) $attrValue : null;
            $valFloat = is_float($attrValue) || (is_string($attrValue) && preg_match('/^-?\d+(?:[.,]\d+)?/', trim((string) $attrValue)))
                ? (float) str_replace(',', '.', (string) $attrValue) : null;

            $attrQuery->whereExists(function ($sub) use ($nameNorm, $valueNorm, $valInt, $valFloat) {
                $sub->selectRaw('1')
                    ->from('system_product_attributes as af')
                    ->whereColumn('af.system_product_id', 'sp.id')
                    ->where('af.attr_name', $nameNorm);

                if ($valInt !== null) {
                    $sub->where('af.value_int', $valInt);
                } elseif ($valFloat !== null) {
                    $sub->where('af.value_float', $valFloat);
                } else {
                    $sub->where('af.attr_value', $valueNorm);
                }
            });
        }

        $rows = $attrQuery->get();

        $attributes = [];
        foreach ($rows as $r) {
            if (count($attributes[$r->attr_name] ?? []) >= 50) {
                continue;
            }
            $value = match ($r->attr_type) {
                SystemProductAttribute::TYPE_INT => $r->value_int,
                SystemProductAttribute::TYPE_FLOAT => $r->value_float,
                default => $r->attr_value,
            };
            $attributes[$r->attr_name][] = [
                'value' => $value,
                'count' => (int) $r->product_count,
            ];
        }

        return [
            'attributes' => $attributes,
            'price' => [
                'min' => $priceRange?->min_price !== null ? (int) $priceRange->min_price : null,
                'max' => $priceRange?->max_price !== null ? (int) $priceRange->max_price : null,
            ],
        ];
        }, $tags);
    }
}
