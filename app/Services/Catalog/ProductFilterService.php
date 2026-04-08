<?php

namespace App\Services\Catalog;

use App\Models\SystemProduct;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use App\Services\Catalog\ProductCache;

class ProductFilterService
{
    private const CACHE_TTL = 60;

    private function cacheKey(?int $categoryId, ?float $priceMin, ?float $priceMax, array $attributes, ?string $search, ?int $page = null, ?int $perPage = null): string
    {
        $payload = array_filter([
            'c' => $categoryId,
            'pmin' => $priceMin,
            'pmax' => $priceMax,
            'attrs' => $attributes,
            'q' => $search !== null ? trim($search) : null,
            'page' => $page,
            'per' => $perPage,
        ], fn ($v) => $v !== null && $v !== '');
        return 'product_filter:' . hash('sha256', json_encode($payload));
    }
    /**
     * @param  int|null  $categoryId
     * @param  float|null  $priceMin
     * @param  float|null  $priceMax
     * @param  array<string, string|int|float>  $attributes  e.g. ['color' => 'red', 'size' => 42]
     * @param  string|null  $search
     * @return Builder<SystemProduct>
     */
    public function query(
        ?int $categoryId = null,
        ?float $priceMin = null,
        ?float $priceMax = null,
        array $attributes = [],
        ?string $search = null
    ): Builder {
        $q = SystemProduct::query()->from('system_products as sp');

        $q->where('sp.status', SystemProduct::STATUS_PUBLISHED);
        $q->when($categoryId !== null, fn ($b) => $b->where('sp.category_id', $categoryId));
        $q->when($priceMin !== null, fn ($b) => $b->where('sp.price_raw', '>=', (int) $priceMin));
        $q->when($priceMax !== null, fn ($b) => $b->where('sp.price_raw', '<=', (int) $priceMax));
        $q->when($search !== null && trim($search) !== '', fn ($b) => $b->where('sp.name', 'like', '%' . trim($search) . '%'));

        foreach ($attributes as $attrName => $attrValue) {
            $nameNorm = strtolower(trim((string) $attrName));
            $valueNorm = is_string($attrValue) ? strtolower(trim($attrValue)) : $attrValue;

            $valInt = is_int($attrValue) || (is_string($attrValue) && preg_match('/^-?\d+$/', trim((string) $attrValue)))
                ? (int) $attrValue : null;
            $valFloat = is_float($attrValue) || (is_string($attrValue) && preg_match('/^-?\d+(?:[.,]\d+)?/', trim((string) $attrValue)))
                ? (float) str_replace(',', '.', (string) $attrValue) : null;

            $q->whereExists(function ($sub) use ($nameNorm, $valueNorm, $valInt, $valFloat) {
                $sub->selectRaw('1')
                    ->from('system_product_attributes as spa')
                    ->whereColumn('spa.system_product_id', 'sp.id')
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

        $q->select('sp.*')->orderByDesc('sp.created_at');

        return $q;
    }

    /**
     * @param  array<string, string|int|float>  $attributes
     * @return Collection<int, SystemProduct>
     */
    public function get(
        ?int $categoryId = null,
        ?float $priceMin = null,
        ?float $priceMax = null,
        array $attributes = [],
        ?string $search = null
    ): Collection {
        $key = $this->cacheKey($categoryId, $priceMin, $priceMax, $attributes, $search);
        $tags = $categoryId !== null ? ['category_' . $categoryId] : [];
        return ProductCache::remember($key, self::CACHE_TTL, fn () => $this->query($categoryId, $priceMin, $priceMax, $attributes, $search)->get(), $tags);
    }

    /**
     * @param  array<string, string|int|float>  $attributes
     * @return LengthAwarePaginator<SystemProduct>
     */
    public function paginate(
        ?int $categoryId = null,
        ?float $priceMin = null,
        ?float $priceMax = null,
        array $attributes = [],
        ?string $search = null,
        int $perPage = 20
    ): LengthAwarePaginator {
        $page = request()->input('page', 1);
        $key = $this->cacheKey($categoryId, $priceMin, $priceMax, $attributes, $search, (int) $page, $perPage);
        $tags = $categoryId !== null ? ['category_' . $categoryId] : [];
        return ProductCache::remember($key, self::CACHE_TTL, fn () => $this->query($categoryId, $priceMin, $priceMax, $attributes, $search)->paginate($perPage), $tags);
    }
}
