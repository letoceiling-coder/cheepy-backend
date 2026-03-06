<?php

namespace App\Services;

use App\Models\AttributeDictionary;
use App\Models\AttributeRule;
use App\Models\AttributeSynonym;
use App\Models\AttributeValueNormalization;
use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Production-grade attribute extraction service.
 *
 * Pipeline for each product:
 *   1. normalize(text)              — strip HTML/CSS artefacts, collapse whitespace
 *   2. applyRules()                 — regex + keyword from attribute_rules table
 *   3. applySynonyms()              — word→canonical from attribute_synonyms
 *   4. applyCanonical()             — exact/fuzzy from attribute_value_normalization
 *   5. validateDictionary()         — keep only values in attribute_dictionary (optional)
 *   6. assignConfidence()           — 0.9 regex / 0.8 synonym / 0.7 keyword
 *   7. persist()                    — upsert product_attributes
 *
 * Redis caches (per-process TTL 1h):
 *   attr_rules_all         — all enabled rules ordered by attribute_key + priority
 *   attr_synonyms_all      — all synonyms
 *   attr_canonical_all     — all canonical normalization rows
 *   attr_dictionary_all    — all dictionary entries
 *   attr_parse:{hash}      — parsed result for identical description hashes
 *   filters:category:{id}  — facet counts per category (invalidated on rebuild)
 */
class AttributeExtractionService
{
    private const CACHE_TTL       = 3600;
    private const PARSE_CACHE_TTL = 86400; // 24h for identical description hashes

    // confidence scores per match type
    public const CONF_REGEX   = 0.90;
    public const CONF_SYNONYM = 0.80;
    public const CONF_KEYWORD = 0.70;
    public const CONF_DICT    = 0.95; // value confirmed in dictionary

    // ─────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────

    /**
     * Extract and persist attributes for a single product.
     * Returns list of saved attribute rows.
     */
    public function extractAndSave(Product $product): array
    {
        $text = $this->buildText($product);
        $hash = md5($text);
        $cacheKey = "attr_parse:{$hash}";

        $extracted = Cache::remember($cacheKey, self::PARSE_CACHE_TTL, function () use ($text) {
            return $this->extractFromText($text);
        });

        $this->persist($product, $extracted);

        return $extracted;
    }

    /**
     * Extract attributes from arbitrary text (no DB save).
     * Used for admin "test rule" endpoint.
     */
    public function extractFromText(string $text): array
    {
        $rules      = $this->getRules();
        $synonyms   = $this->getSynonyms();
        $canonical  = $this->getCanonical();
        $dictionary = $this->getDictionary();
        $normalized = $this->normalize($text);

        $results    = [];
        $seenKeys   = [];  // track which attribute_keys already have high-confidence matches

        foreach ($rules as $rule) {
            // Skip this rule if a high-confidence match already found for this key
            if (isset($seenKeys[$rule->attribute_key]) && $seenKeys[$rule->attribute_key] >= self::CONF_REGEX) {
                continue;
            }

            $matchType = null;
            $value     = null;

            if ($rule->rule_type === 'regex') {
                $value = $this->applyRegex($rule->pattern, $normalized);
                $matchType = 'regex';
            } elseif ($rule->rule_type === 'keyword') {
                $value = $this->applyKeyword($rule->pattern, $normalized);
                $matchType = 'keyword';
            }

            if ($value === null || $value === '') continue;

            // Apply synonyms
            if ($rule->apply_synonyms) {
                [$value, $matchType] = $this->applySynonyms($value, $rule->attribute_key, $synonyms, $matchType);
            }

            // Apply canonical normalization (exact → normalized_value)
            [$value, $conf] = $this->applyCanonical($value, $rule->attribute_key, $canonical, $matchType);

            $value = $this->cleanValue($value);
            if ($value === '') continue;

            // Split multi-value attributes
            $values = $this->splitMultiValue($rule->attribute_key, $value);

            foreach ($values as $v) {
                $v = trim($v);
                if ($v === '') continue;

                // Validate + boost confidence if value is in dictionary
                $dictConf = $this->validateDictionary($v, $rule->attribute_key, $dictionary);
                $finalConf = $dictConf > 0 ? self::CONF_DICT : $conf;

                $results[] = [
                    'attribute_key' => $rule->attribute_key,
                    'attr_name'     => $rule->display_name,
                    'attr_value'    => $v,
                    'attr_type'     => $rule->attr_type,
                    'confidence'    => round($finalConf, 2),
                    'match_type'    => $matchType,
                ];
            }

            if (count($values) > 0) {
                $seenKeys[$rule->attribute_key] = $conf;
            }
        }

        return $results;
    }

    /**
     * Rebuild attributes for all/filtered products.
     * Returns ['processed' => int, 'saved' => int]
     */
    public function rebuildAll(callable $progress = null, ?int $categoryId = null, int $chunkSize = 200): array
    {
        $processed = 0;
        $saved     = 0;

        $query = Product::select(['id', 'title', 'description', 'category_id'])->orderBy('id');
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $query->chunk($chunkSize, function (Collection $products) use (&$processed, &$saved, $progress) {
            foreach ($products as $product) {
                $attrs = $this->extractAndSave($product);
                $processed++;
                $saved += count($attrs);
                if ($progress) $progress($processed);
            }
        });

        // Invalidate all category filter caches
        $this->invalidateFilterCaches();

        return ['processed' => $processed, 'saved' => $saved];
    }

    /**
     * Clear all service caches (call after any rule/synonym/canonical/dictionary change).
     */
    public function clearCache(): void
    {
        Cache::forget('attr_rules_all');
        Cache::forget('attr_synonyms_all');
        Cache::forget('attr_canonical_all');
        Cache::forget('attr_dictionary_all');
    }

    public function invalidateFilterCaches(): void
    {
        // Pattern-delete: filters:category:*
        try {
            $redis = Cache::getStore()->getRedis();
            $prefix = config('cache.prefix') . ':filters:category:*';
            $keys = $redis->keys($prefix);
            if (!empty($keys)) {
                $redis->del($keys);
            }
        } catch (\Throwable $e) {
            Log::warning('AttributeExtractionService: failed to invalidate filter caches', ['error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // PRIVATE — PIPELINE STEPS
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
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Remove CSS artefacts (parser sometimes grabs page styles)
        $text = preg_replace('/\{[^}]{1,500}\}/s', ' ', $text) ?? $text;
        // Collapse visual separators
        $text = preg_replace('/(\.\s*){3,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/(\-\s*){3,}/u', ' ', $text) ?? $text;
        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return implode("\n", array_map('trim', explode("\n", $text)));
    }

    private function applyRegex(string $pattern, string $text): ?string
    {
        try {
            if (@preg_match('/' . $pattern . '/iu', $text, $m) && isset($m[1])) {
                return trim($m[1]);
            }
        } catch (\Throwable $e) {
            Log::warning('AttributeExtractionService: invalid regex', [
                'pattern' => $pattern, 'error' => $e->getMessage(),
            ]);
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

    /**
     * Apply synonym table. Returns [$value, $matchType].
     */
    private function applySynonyms(string $value, string $attrKey, Collection $synonyms, string $matchType): array
    {
        $lower = mb_strtolower(trim($value));

        // Exact match
        $match = $synonyms->first(fn($s) =>
            ($s->attribute_key === null || $s->attribute_key === $attrKey)
            && mb_strtolower($s->word) === $lower
        );
        if ($match) {
            return [$match->normalized_value, 'synonym'];
        }

        // Word-by-word replacement inside the value
        $changed = false;
        foreach ($synonyms->where('attribute_key', $attrKey) as $synonym) {
            $new = preg_replace(
                '/(?<![а-яёa-z0-9])' . preg_quote(mb_strtolower($synonym->word), '/') . '(?![а-яёa-z0-9])/iu',
                $synonym->normalized_value,
                $value
            ) ?? $value;
            if ($new !== $value) {
                $value = $new;
                $changed = true;
            }
        }

        return [$value, $changed ? 'synonym' : $matchType];
    }

    /**
     * Apply canonical normalization. Returns [$value, $confidence].
     */
    private function applyCanonical(string $value, string $attrKey, Collection $canonical, string $matchType): array
    {
        $lower = mb_strtolower(trim($value));
        $conf  = $matchType === 'regex' ? self::CONF_REGEX :
                ($matchType === 'synonym' ? self::CONF_SYNONYM : self::CONF_KEYWORD);

        // Exact match
        $match = $canonical->first(fn($c) =>
            $c->attribute_key === $attrKey && mb_strtolower($c->raw_value) === $lower
        );
        if ($match) {
            return [$match->normalized_value, $conf + 0.05];
        }

        // Partial match (raw_value contained in value)
        foreach ($canonical->where('attribute_key', $attrKey) as $c) {
            if (str_contains($lower, mb_strtolower($c->raw_value))) {
                return [$c->normalized_value, $conf];
            }
        }

        return [$value, $conf];
    }

    /**
     * Check if value is in dictionary. Returns 1.0 if found, 0 otherwise.
     */
    private function validateDictionary(string $value, string $attrKey, Collection $dictionary): float
    {
        $lower = mb_strtolower(trim($value));
        $found = $dictionary->contains(fn($d) =>
            $d->attribute_key === $attrKey && mb_strtolower($d->value) === $lower
        );
        return $found ? 1.0 : 0.0;
    }

    private function cleanValue(string $value): string
    {
        // Drop if CSS leaked into value
        if (str_contains($value, '{') || str_contains($value, 'font-size') || str_contains($value, 'color:')) {
            return '';
        }
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        return mb_substr($value, 0, 490);
    }

    private function splitMultiValue(string $attrKey, string $value): array
    {
        if ($attrKey === 'color') {
            $parts = preg_split('/[,;\/]+/', $value) ?: [$value];
            return array_filter(array_map('trim', $parts));
        }
        if ($attrKey === 'size') {
            $parts = preg_split('/[\s,]+/', $value) ?: [$value];
            $cleaned = array_filter(array_map('trim', $parts), fn($p) => $p !== '');
            $sizeRx  = '/^(XS|S|M|L|XL|XXL|[2-5]XL|[3-6]\d)$/i';
            $allSizes = count($cleaned) > 0 && count(array_filter($cleaned, fn($p) => !preg_match($sizeRx, $p))) === 0;
            return $allSizes ? $cleaned : [$value];
        }
        return [$value];
    }

    private function persist(Product $product, array $extracted): void
    {
        if (empty($extracted)) return;

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
                'confidence'  => $attr['confidence'] ?? 1.0,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        DB::table('product_attributes')->insert($rows);
    }

    // ─────────────────────────────────────────────────────────────────
    // CACHE LOADERS
    // ─────────────────────────────────────────────────────────────────

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
        return Cache::remember('attr_synonyms_all', self::CACHE_TTL, fn() => AttributeSynonym::all());
    }

    private function getCanonical(): Collection
    {
        return Cache::remember('attr_canonical_all', self::CACHE_TTL, fn() => AttributeValueNormalization::all());
    }

    private function getDictionary(): Collection
    {
        return Cache::remember('attr_dictionary_all', self::CACHE_TTL, fn() => AttributeDictionary::all());
    }
}
