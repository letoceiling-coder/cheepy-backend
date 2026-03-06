<?php

namespace App\Services;

use App\Models\AttributeRule;
use App\Models\AttributeSynonym;
use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Extracts structured attributes from raw product text.
 *
 * Pipeline per product:
 *   1. Normalize text (unicode, trim, collapse whitespace)
 *   2. Apply rules from `attribute_rules` table in priority order
 *      a. regex rules  → preg_match, capture group 1
 *      b. keyword rules → simple str_contains on lowercase text
 *   3. Apply synonyms from `attribute_synonyms`
 *   4. Write results to `product_attributes`
 */
class AttributeExtractionService
{
    /** How long to cache rules & synonyms (seconds). Invalidated after any rule change. */
    private const CACHE_TTL = 3600;

    // ─────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────

    /**
     * Extract attributes for a single product and persist them.
     * Returns the list of saved attribute arrays.
     */
    public function extractAndSave(Product $product): array
    {
        $text = $this->buildText($product);
        $extracted = $this->extractFromText($text);

        $this->persist($product, $extracted);

        return $extracted;
    }

    /**
     * Extract attributes from arbitrary text without persisting anything.
     * Useful for admin "test rule" endpoint.
     */
    public function extractFromText(string $text): array
    {
        $rules    = $this->getRules();
        $synonyms = $this->getSynonyms();
        $normalized = $this->normalize($text);

        $results = [];

        foreach ($rules as $rule) {
            $value = null;

            if ($rule->rule_type === 'regex') {
                $value = $this->applyRegex($rule->pattern, $normalized);
            } elseif ($rule->rule_type === 'keyword') {
                $value = $this->applyKeyword($rule->pattern, $normalized);
            }

            if ($value === null || $value === '') {
                continue;
            }

            if ($rule->apply_synonyms) {
                $value = $this->applySynonyms($value, $rule->attribute_key, $synonyms);
            }

            $value = $this->cleanValue($value);

            if ($value === '') {
                continue;
            }

            // For multi-value attributes (size, color) split by common separators
            $values = $this->splitMultiValue($rule->attribute_key, $value);

            foreach ($values as $v) {
                $v = trim($v);
                if ($v === '') continue;

                $results[] = [
                    'attribute_key' => $rule->attribute_key,
                    'attr_name'     => $rule->display_name,
                    'attr_value'    => $v,
                    'attr_type'     => $rule->attr_type,
                ];
            }

            // If a high-priority match was found, skip lower-priority rules for same key
            if ($rule->priority <= 25 && count($values) > 0) {
                $rules = $rules->reject(fn($r) => $r->attribute_key === $rule->attribute_key && $r->priority > $rule->priority);
            }
        }

        return $results;
    }

    /**
     * Rebuild attributes for ALL products (called from artisan command).
     * Returns [processed, saved] counts.
     */
    public function rebuildAll(callable $progress = null): array
    {
        $processed = 0;
        $saved     = 0;

        Product::select(['id', 'title', 'description', 'category_id'])
            ->orderBy('id')
            ->chunk(200, function (Collection $products) use (&$processed, &$saved, $progress) {
                foreach ($products as $product) {
                    $attrs = $this->extractAndSave($product);
                    $processed++;
                    $saved += count($attrs);
                    if ($progress) $progress($processed);
                }
            });

        return ['processed' => $processed, 'saved' => $saved];
    }

    /**
     * Clear rules + synonyms cache (call after any rule/synonym change).
     */
    public function clearCache(): void
    {
        Cache::forget('attr_rules_all');
        Cache::forget('attr_synonyms_all');
    }

    // ─────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────

    private function buildText(Product $product): string
    {
        return implode("\n", array_filter([
            $product->title ?? '',
            $product->description ?? '',
        ]));
    }

    private function normalize(string $text): string
    {
        // Remove HTML entities and tags if any sneak in
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse repeating dots, dashes used as visual separators (e.g. ". . . . .")
        $text = preg_replace('/(\.\s*){3,}/', ' ', $text);
        $text = preg_replace('/(\-\s*){3,}/', ' ', $text);
        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Trim each line
        $lines = array_map('trim', explode("\n", $text));
        return implode("\n", $lines);
    }

    private function applyRegex(string $pattern, string $text): ?string
    {
        try {
            if (@preg_match('/' . $pattern . '/iu', $text, $m) && isset($m[1])) {
                return trim($m[1]);
            }
        } catch (\Throwable $e) {
            Log::warning('AttributeExtractionService: invalid regex', ['pattern' => $pattern, 'error' => $e->getMessage()]);
        }
        return null;
    }

    private function applyKeyword(string $keyword, string $text): ?string
    {
        if (str_contains(mb_strtolower($text), mb_strtolower($keyword))) {
            return $keyword;
        }
        return null;
    }

    private function applySynonyms(string $value, string $attrKey, Collection $synonyms): string
    {
        $lower = mb_strtolower(trim($value));
        // exact match first
        $match = $synonyms->first(fn($s) =>
            ($s->attribute_key === null || $s->attribute_key === $attrKey)
            && mb_strtolower($s->word) === $lower
        );
        if ($match) return $match->normalized_value;

        // word-by-word replacement within the value
        foreach ($synonyms->where('attribute_key', $attrKey) as $synonym) {
            $value = preg_replace(
                '/(?<![а-яёa-z])' . preg_quote(mb_strtolower($synonym->word), '/') . '(?![а-яёa-z])/iu',
                $synonym->normalized_value,
                $value
            ) ?? $value;
        }

        return $value;
    }

    private function cleanValue(string $value): string
    {
        // Remove CSS leak (happens when parser grabs page CSS into text)
        if (str_contains($value, '{') || str_contains($value, 'font-size')) {
            return '';
        }
        $value = preg_replace('/\s+/', ' ', trim($value));
        return mb_substr($value, 0, 490);
    }

    /**
     * For multi-value attribute types, split by separator.
     */
    private function splitMultiValue(string $attrKey, string $value): array
    {
        if (in_array($attrKey, ['color'], true)) {
            // "черный, белый, бежевый" → ["черный", "белый", "бежевый"]
            $parts = preg_split('/[,;\/]+/', $value);
            return array_filter(array_map('trim', $parts));
        }
        if ($attrKey === 'size') {
            // Try to split letter sizes: "S M L XL" / "S,M,L,XL" / "S-M-L"
            // But keep numeric ranges like "42-48" as-is
            $parts = preg_split('/[\s,]+/', $value);
            $cleaned = array_filter(array_map('trim', $parts), fn($p) => $p !== '');
            // If all parts look like sizes, return them; else return as one value
            $sizePattern = '/^(XS|S|M|L|XL|XXL|2XL|3XL|4XL|5XL|[3-6]\d)$/i';
            $allAreSizes = count($cleaned) > 0 && count(array_filter($cleaned, fn($p) => !preg_match($sizePattern, $p))) === 0;
            return $allAreSizes ? $cleaned : [$value];
        }
        return [$value];
    }

    private function persist(Product $product, array $extracted): void
    {
        if (empty($extracted)) return;

        // Delete old extracted attributes (keep manually entered ones)
        ProductAttribute::where('product_id', $product->id)->delete();

        $rows = [];
        $now  = now();
        foreach ($extracted as $attr) {
            $rows[] = [
                'product_id'  => $product->id,
                'category_id' => $product->category_id,
                'attr_name'   => mb_substr($attr['attr_name'], 0, 199),
                'attr_value'  => mb_substr($attr['attr_value'], 0, 499),
                'attr_type'   => $attr['attr_type'],
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        DB::table('product_attributes')->insert($rows);
    }

    private function getRules(): Collection
    {
        return Cache::remember('attr_rules_all', self::CACHE_TTL, function () {
            return AttributeRule::where('enabled', true)
                ->orderBy('attribute_key')
                ->orderBy('priority')
                ->get();
        });
    }

    private function getSynonyms(): Collection
    {
        return Cache::remember('attr_synonyms_all', self::CACHE_TTL, function () {
            return AttributeSynonym::all();
        });
    }
}
