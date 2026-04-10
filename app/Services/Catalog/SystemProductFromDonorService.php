<?php

namespace App\Services\Catalog;

use App\Models\CategoryMapping;
use App\Models\DonorCategory;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductPhoto;
use App\Models\ProductSource;
use App\Models\SystemProduct;
use App\Models\SystemProductAttribute;
use App\Models\SystemProductPhoto;
use Illuminate\Support\Facades\DB;

/**
 * Creates system_product from donor product. Copies name, description, price, seller,
 * attributes, photos. Resolves catalog_category via donor_category mapping.
 */
class SystemProductFromDonorService
{
    public function createFromDonor(
        Product $donor,
        string $status = SystemProduct::STATUS_DRAFT
    ): SystemProduct {
        return DB::transaction(function () use ($donor, $status) {
            $catalogCategoryId = $this->resolveCatalogCategoryId($donor->category_id);

            $sp = SystemProduct::create([
                'name' => $donor->title ?? 'Untitled',
                'description' => $donor->description,
                'price' => $donor->price,
                'price_raw' => $donor->price_raw,
                'status' => $status,
                'seller_id' => $donor->seller_id,
                'category_id' => $catalogCategoryId,
                'brand_id' => $donor->brand_id,
            ]);

            ProductSource::create([
                'system_product_id' => $sp->id,
                'donor_product_id' => $donor->id,
                'source' => ProductSource::SOURCE_PARSER,
                'donor_updated_at' => $donor->updated_at,
            ]);

            $this->copyAttributes($donor, $sp);
            $this->copyPhotos($donor, $sp);

            return $sp->load(['productSources.donorProduct', 'attributes', 'photos', 'seller', 'category', 'brand']);
        });
    }

    private function resolveCatalogCategoryId(?int $parserCategoryId): ?int
    {
        if ($parserCategoryId === null) {
            return null;
        }

        $donorCat = DonorCategory::where('external_id', (string) $parserCategoryId)->first();
        if ($donorCat === null) {
            return null;
        }

        $mapping = CategoryMapping::where('donor_category_id', $donorCat->id)->first();

        return $mapping?->catalog_category_id;
    }

    private function copyAttributes(Product $donor, SystemProduct $sp): void
    {
        SystemProductAttribute::where('system_product_id', $sp->id)->delete();

        $attrs = ProductAttribute::where('product_id', $donor->id)->get();

        $seen = [];
        foreach ($attrs as $a) {
            $raw = mb_substr((string) $a->attr_value, 0, 500);
            $attrValue = strtolower(trim($raw));
            $attrName = strtolower(trim((string) $a->attr_name));
            $dedupeKey = $attrName."\0".$attrValue;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $attrType = $this->detectAttrType($attrValue);

            $payload = [
                'system_product_id' => $sp->id,
                'attr_name' => $attrName,
                'attr_value' => $attrValue,
                'attr_value_original' => $raw,
                'attr_type' => $attrType,
            ];

            if ($attrType === SystemProductAttribute::TYPE_INT) {
                $payload['value_int'] = $this->parseInt($attrValue);
            } elseif ($attrType === SystemProductAttribute::TYPE_FLOAT) {
                $payload['value_float'] = $this->parseFloat($attrValue);
            }

            SystemProductAttribute::create($payload);
        }
    }

    private function detectAttrType(string $value): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', str_replace(',', '.', $value)));
        if ($normalized === '') {
            return SystemProductAttribute::TYPE_TEXT;
        }
        if (preg_match('/^-?\d+$/', $normalized)) {
            return SystemProductAttribute::TYPE_INT;
        }
        if (is_numeric($normalized)) {
            return SystemProductAttribute::TYPE_FLOAT;
        }
        return SystemProductAttribute::TYPE_TEXT;
    }

    private function parseInt(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^-?\d+/', $value, $m)) {
            return (int) $m[0];
        }
        return null;
    }

    private function parseFloat(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/-?\d+(?:[.,]\d+)?/', $value, $m)) {
            return (float) str_replace(',', '.', $m[0]);
        }
        return null;
    }

    private function copyPhotos(Product $donor, SystemProduct $sp): void
    {
        $photos = ProductPhoto::where('product_id', $donor->id)->orderBy('sort_order')->get();

        if ($photos->isNotEmpty()) {
            foreach ($photos as $i => $ph) {
                $url = $this->photoUrl($ph);
                if ($url) {
                    SystemProductPhoto::create([
                        'system_product_id' => $sp->id,
                        'url' => mb_substr($url, 0, 1000),
                        'is_primary' => $ph->is_primary ?? ($i === 0),
                        'sort_order' => $ph->sort_order ?? $i,
                    ]);
                }
            }
        } else {
            $jsonPhotos = $donor->photos ?? [];
            if (is_array($jsonPhotos)) {
                foreach ($jsonPhotos as $i => $url) {
                    if (is_string($url) && str_starts_with($url, 'http')) {
                        SystemProductPhoto::create([
                            'system_product_id' => $sp->id,
                            'url' => mb_substr($url, 0, 1000),
                            'is_primary' => $i === 0,
                            'sort_order' => $i,
                        ]);
                    }
                }
            }
        }
    }

    private function photoUrl(ProductPhoto $ph): ?string
    {
        if (!empty($ph->cdn_url)) {
            return $ph->cdn_url;
        }
        if (!empty($ph->local_path) && file_exists(storage_path('app/' . $ph->local_path))) {
            return url('storage/' . $ph->local_path);
        }
        return $ph->original_url;
    }
}
