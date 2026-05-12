<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductSimilar;
use App\Models\ProductSource;
use App\Models\SystemProduct;
use App\Models\SystemProductPhoto;
use Illuminate\Support\Collection;

/**
 * Варианты цвета с донора (.similar_products) → видимые system_products для витрины.
 */
class StorefrontColorVariantsService
{
    /**
     * Полная линейка для карточки товара (только если на витрине ≥ 2 вариантов).
     *
     * @return list<array{id: string, color: string, thumbnail: string|null, title: string, is_current: bool}>
     */
    public function detailColorVariants(SystemProduct $currentSp, PublicSystemCatalogService $catalog): array
    {
        $currentSp->loadMissing(['productSources.donorProduct']);

        $donor = $currentSp->productSources->first(fn ($ps) => $ps->source === ProductSource::SOURCE_PARSER)?->donorProduct;
        if ($donor === null) {
            return [];
        }

        $donor->loadMissing(['similarLinks' => fn ($q) => $q->orderBy('sort_order')]);

        $extIds = $this->collectRelatedExternalIds($donor);
        if (count($extIds) <= 1) {
            return [];
        }

        $resolved = $catalog->resolveVisibleSystemProductsByRequestedIds($extIds);
        if (count($resolved) < 2) {
            return [];
        }

        $own = $this->normalizeExternalId((string) $donor->external_id);
        $orderedExt = $this->orderExternalIdsForDisplay($donor, $own, array_keys($resolved));

        $spIds = collect($resolved)->pluck('id')->unique()->filter()->map(fn ($id) => (int) $id)->values()->all();
        $loaded = SystemProduct::query()
            ->whereIn('id', $spIds)
            ->with([
                'attributes',
                'photos' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order'),
            ])
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($orderedExt as $ext) {
            if (! isset($resolved[$ext])) {
                continue;
            }
            $sp = $loaded->get((int) $resolved[$ext]->id);
            if ($sp === null) {
                continue;
            }
            $out[] = [
                'id' => $catalog->storefrontPublicProductId($sp),
                'color' => $this->colorLabelFromSp($sp) ?? '',
                'thumbnail' => $this->firstPhotoUrl($sp),
                'title' => (string) $sp->name,
                'is_current' => (int) $sp->id === (int) $currentSp->id,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, SystemProduct>  $systemProducts
     * @return array<int, array{color_variants_count: int, color_variant_thumbnails: list<string>}>
     */
    public function batchCardSummaries(Collection $systemProducts, PublicSystemCatalogService $catalog): array
    {
        if ($systemProducts->isEmpty()) {
            return [];
        }

        $donorPks = [];
        $spToDonorMeta = [];
        foreach ($systemProducts as $sp) {
            $sp->loadMissing('productSources.donorProduct:id,external_id');
            $donor = $sp->productSources->first(fn ($ps) => $ps->source === ProductSource::SOURCE_PARSER)?->donorProduct;
            if ($donor && $donor->id) {
                $pk = (int) $donor->id;
                $donorPks[] = $pk;
                $ext = $this->normalizeExternalId((string) $donor->external_id);
                if ($ext !== '') {
                    $spToDonorMeta[(int) $sp->id] = ['donor_pk' => $pk, 'ext' => $ext];
                }
            }
        }

        if ($donorPks === []) {
            return $this->emptySummaries($systemProducts);
        }

        $donorPks = array_values(array_unique($donorPks));
        $donors = Product::query()
            ->whereIn('id', $donorPks)
            ->with(['similarLinks' => fn ($q) => $q->orderBy('sort_order')])
            ->get()
            ->keyBy('id');

        $allExtUnion = [];
        foreach ($spToDonorMeta as $meta) {
            $d = $donors->get($meta['donor_pk']);
            if ($d === null) {
                continue;
            }
            foreach ($this->collectRelatedExternalIds($d) as $e) {
                $allExtUnion[$e] = true;
            }
        }

        $globalResolved = $allExtUnion !== []
            ? $catalog->resolveVisibleSystemProductsByRequestedIds(array_keys($allExtUnion))
            : [];

        $spIds = collect($globalResolved)->pluck('id')->unique()->map(fn ($id) => (int) $id)->all();
        $thumbsBySpId = $this->loadFirstThumbnailsBySystemProductIds($spIds);

        $result = [];
        foreach ($systemProducts as $sp) {
            $sid = (int) $sp->id;
            if (! isset($spToDonorMeta[$sid])) {
                $result[$sid] = ['color_variants_count' => 0, 'color_variant_thumbnails' => []];

                continue;
            }
            $meta = $spToDonorMeta[$sid];
            $d = $donors->get($meta['donor_pk']);
            if ($d === null) {
                $result[$sid] = ['color_variants_count' => 0, 'color_variant_thumbnails' => []];

                continue;
            }

            $groupExt = $this->collectRelatedExternalIds($d);
            $visibleInGroup = [];
            foreach ($groupExt as $e) {
                if (isset($globalResolved[$e])) {
                    $visibleInGroup[$e] = $globalResolved[$e];
                }
            }

            $count = count($visibleInGroup);
            $ownExt = $meta['ext'];
            $thumbs = [];

            foreach ($d->similarLinks as $link) {
                $e = $this->normalizeExternalId((string) $link->related_external_id);
                if ($e === '' || $e === $ownExt) {
                    continue;
                }
                $vsp = $visibleInGroup[$e] ?? null;
                if ($vsp !== null) {
                    $u = $thumbsBySpId[(int) $vsp->id] ?? null;
                    if ($u !== null && count($thumbs) < 4) {
                        $thumbs[] = $u;
                    }
                }
            }

            foreach ($visibleInGroup as $e => $vsp) {
                if ((int) $vsp->id === $sid || count($thumbs) >= 4) {
                    continue;
                }
                $u = $thumbsBySpId[(int) $vsp->id] ?? null;
                if ($u !== null && ! in_array($u, $thumbs, true)) {
                    $thumbs[] = $u;
                }
            }

            $result[$sid] = [
                'color_variants_count' => $count,
                'color_variant_thumbnails' => $thumbs,
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, SystemProduct>  $systemProducts
     * @return array<int, array{color_variants_count: int, color_variant_thumbnails: list<string>}>
     */
    private function emptySummaries(Collection $systemProducts): array
    {
        $r = [];
        foreach ($systemProducts as $sp) {
            $r[(int) $sp->id] = ['color_variants_count' => 0, 'color_variant_thumbnails' => []];
        }

        return $r;
    }

    /**
     * Все внешние id вариантов по графу product_similar (как на доноре — одна связная группа).
     * Иначе у карточки, которую парсили без блока .similar_products в этом прогоне, оставались бы только «обратные» ссылки,
     * без полного круга цветов.
     *
     * @return list<string>
     */
    private function collectRelatedExternalIds(Product $donor): array
    {
        $own = $this->normalizeExternalId((string) $donor->external_id);
        if ($own === '' || ! $donor->id) {
            return [];
        }

        $extSet = [$own => true];
        $pidSet = [(int) $donor->id => true];

        for ($iter = 0; $iter < 16; $iter++) {
            $beforeExt = count($extSet);
            $beforePid = count($pidSet);

            $pids = array_keys($pidSet);
            $outgoing = ProductSimilar::query()
                ->whereIn('product_id', $pids)
                ->pluck('related_external_id');
            foreach ($outgoing as $raw) {
                $e = $this->normalizeExternalId((string) $raw);
                if ($e !== '') {
                    $extSet[$e] = true;
                }
            }

            $exts = array_keys($extSet);
            $incomingPids = ProductSimilar::query()
                ->whereIn('related_external_id', $exts)
                ->pluck('product_id');
            foreach ($incomingPids as $pid) {
                if ($pid) {
                    $pidSet[(int) $pid] = true;
                }
            }

            $mappedPids = Product::query()
                ->whereIn('external_id', $exts)
                ->pluck('id');
            foreach ($mappedPids as $pid) {
                $pidSet[(int) $pid] = true;
            }

            if (count($extSet) === $beforeExt && count($pidSet) === $beforePid) {
                break;
            }
        }

        // PHP приводит числовые строки к int-ключам массива — для API резолва нужны строки.
        return array_values(array_unique(array_map(
            fn ($k) => $this->normalizeExternalId((string) $k),
            array_keys($extSet),
        )));
    }

    /**
     * @param  list<string>  $resolvedKeys
     * @return list<string>
     */
    private function orderExternalIdsForDisplay(Product $donor, string $own, array $resolvedKeys): array
    {
        $resolvedSet = array_fill_keys($resolvedKeys, true);
        $ordered = [];
        $links = $donor->similarLinks ?? collect();
        foreach ($links->sortBy('sort_order') as $link) {
            $e = $this->normalizeExternalId((string) $link->related_external_id);
            if ($e !== '' && isset($resolvedSet[$e])) {
                $ordered[] = $e;
            }
        }
        if (isset($resolvedSet[$own]) && ! in_array($own, $ordered, true)) {
            array_unshift($ordered, $own);
        }
        foreach ($resolvedKeys as $e) {
            if (! in_array($e, $ordered, true)) {
                $ordered[] = $e;
            }
        }

        return $ordered;
    }

    private function normalizeExternalId(string $raw): string
    {
        return (string) preg_replace('/\D/', '', $raw);
    }

    private function colorLabelFromSp(SystemProduct $sp): ?string
    {
        foreach ($sp->attributes ?? [] as $a) {
            $key = mb_strtolower((string) ($a->attribute_key ?? ''));
            $name = mb_strtolower((string) ($a->attr_name ?? ''));
            if ($key === 'color' || str_contains($name, 'цвет') || str_contains($name, 'color')) {
                $v = trim((string) ($a->attr_value ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return null;
    }

    private function firstPhotoUrl(SystemProduct $sp): ?string
    {
        $first = $sp->photos->first();

        return $first && $first->url ? (string) $first->url : null;
    }

    /**
     * @param  array<int>  $systemProductIds
     * @return array<int, string>
     */
    private function loadFirstThumbnailsBySystemProductIds(array $systemProductIds): array
    {
        if ($systemProductIds === []) {
            return [];
        }
        $rows = SystemProductPhoto::query()
            ->whereIn('system_product_id', $systemProductIds)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->get(['system_product_id', 'url']);
        $map = [];
        foreach ($rows as $row) {
            $pid = (int) $row->system_product_id;
            if (! isset($map[$pid]) && $row->url) {
                $map[$pid] = (string) $row->url;
            }
        }

        return $map;
    }
}
