<?php

namespace App\Console\Commands;

use App\Models\AttributeDictionary;
use App\Models\ProductAttribute;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditAttributes extends Command
{
    protected $signature = 'attributes:audit
                            {--key=   : Filter by attribute_key (size, material, etc.)}
                            {--top=20 : How many top values to show per key}
                            {--min-conf=0.5 : Show attributes below this confidence threshold}';

    protected $description = 'Audit product_attributes: top values, unknowns, duplicates, confidence stats';

    public function handle(): int
    {
        $filterKey = $this->option('key');
        $top       = (int) ($this->option('top') ?: 20);
        $minConf   = (float) ($this->option('min-conf') ?: 0.5);

        $this->info('');
        $this->line('══════════════════════════════════════════════════════════════');
        $this->info('  ATTRIBUTE SYSTEM AUDIT');
        $this->line('══════════════════════════════════════════════════════════════');

        // ── Basic counts ──────────────────────────────────────────────
        $totalProducts = \App\Models\Product::count();
        $withAttrs     = ProductAttribute::distinct('product_id')->count('product_id');
        $totalRows     = ProductAttribute::count();
        $avgPerProduct = $withAttrs > 0 ? round($totalRows / $withAttrs, 1) : 0;

        $this->table(['Metric', 'Value'], [
            ['Total products',           number_format($totalProducts)],
            ['Products with attributes', number_format($withAttrs) . ' (' . round($withAttrs / max($totalProducts, 1) * 100, 1) . '%)'],
            ['Total attribute rows',     number_format($totalRows)],
            ['Avg attrs per product',    $avgPerProduct],
        ]);

        // ── Per-key statistics ─────────────────────────────────────────
        $keys = ProductAttribute::select('attr_name')
            ->when($filterKey, fn($q) => $q->where('attr_name', 'LIKE', "%{$filterKey}%"))
            ->groupBy('attr_name')
            ->orderByRaw('COUNT(*) DESC')
            ->pluck('attr_name');

        foreach ($keys as $attrName) {
            $this->newLine();
            $this->line('─────────────────────────────────────────────────────────────');
            $this->info("  ATTRIBUTE: {$attrName}");
            $this->line('─────────────────────────────────────────────────────────────');

            // Top values
            $topValues = ProductAttribute::where('attr_name', $attrName)
                ->select('attr_value', DB::raw('COUNT(*) as cnt'), DB::raw('AVG(confidence) as avg_conf'))
                ->groupBy('attr_value')
                ->orderByDesc('cnt')
                ->limit($top)
                ->get();

            $this->line("  Top {$top} values:");
            $this->table(
                ['Value', 'Count', 'Avg Confidence'],
                $topValues->map(fn($r) => [
                    mb_substr($r->attr_value, 0, 50),
                    $r->cnt,
                    number_format((float) $r->avg_conf, 2),
                ])->toArray()
            );

            // Low confidence values
            $lowConf = ProductAttribute::where('attr_name', $attrName)
                ->where('confidence', '<', $minConf)
                ->select('attr_value', DB::raw('COUNT(*) as cnt'), DB::raw('AVG(confidence) as avg_conf'))
                ->groupBy('attr_value')
                ->orderByDesc('cnt')
                ->limit(10)
                ->get();

            if ($lowConf->isNotEmpty()) {
                $this->warn("  Low confidence (< {$minConf}) values:");
                $this->table(
                    ['Value', 'Count', 'Avg Conf'],
                    $lowConf->map(fn($r) => [
                        mb_substr($r->attr_value, 0, 50), $r->cnt, number_format((float) $r->avg_conf, 2)
                    ])->toArray()
                );
            }

            // Values NOT in dictionary
            $attrKey = $this->guessKey($attrName);
            if ($attrKey) {
                $dictValues = AttributeDictionary::where('attribute_key', $attrKey)
                    ->pluck('value')
                    ->map('mb_strtolower')
                    ->toArray();

                if (!empty($dictValues)) {
                    $unknown = ProductAttribute::where('attr_name', $attrName)
                        ->select('attr_value', DB::raw('COUNT(*) as cnt'))
                        ->groupBy('attr_value')
                        ->get()
                        ->filter(fn($r) => !in_array(mb_strtolower($r->attr_value), $dictValues, true))
                        ->sortByDesc('cnt')
                        ->take(10);

                    if ($unknown->isNotEmpty()) {
                        $this->warn("  Values NOT in dictionary (possible missing canonical entries):");
                        $this->table(
                            ['Value', 'Count'],
                            $unknown->map(fn($r) => [mb_substr($r->attr_value, 0, 50), $r->cnt])->toArray()
                        );
                    }
                }
            }

            // Confidence distribution
            $confStats = ProductAttribute::where('attr_name', $attrName)
                ->selectRaw('
                    SUM(CASE WHEN confidence >= 0.9 THEN 1 ELSE 0 END) as high,
                    SUM(CASE WHEN confidence >= 0.7 AND confidence < 0.9 THEN 1 ELSE 0 END) as medium,
                    SUM(CASE WHEN confidence < 0.7 THEN 1 ELSE 0 END) as low,
                    COUNT(*) as total
                ')
                ->first();

            $this->line("  Confidence distribution:");
            $this->table(
                ['Range', 'Count', '%'],
                [
                    ['High (≥0.9)',   $confStats->high,   $confStats->total > 0 ? round($confStats->high / $confStats->total * 100) . '%' : '0%'],
                    ['Medium (0.7-0.9)', $confStats->medium, $confStats->total > 0 ? round($confStats->medium / $confStats->total * 100) . '%' : '0%'],
                    ['Low (<0.7)',    $confStats->low,    $confStats->total > 0 ? round($confStats->low / $confStats->total * 100) . '%' : '0%'],
                ]
            );
        }

        $this->newLine();
        $this->info('Audit complete.');
        return 0;
    }

    private function guessKey(string $attrName): ?string
    {
        $map = [
            'размер' => 'size', 'size' => 'size',
            'состав' => 'material', 'ткань' => 'material', 'материал' => 'material', 'material' => 'material',
            'цвет' => 'color', 'color' => 'color',
            'страна' => 'country_of_origin', 'country' => 'country_of_origin',
            'бренд' => 'brand', 'brand' => 'brand',
            'артикул' => 'article', 'article' => 'article',
            'упаков' => 'pack_quantity',
        ];

        $lower = mb_strtolower($attrName);
        foreach ($map as $substr => $key) {
            if (str_contains($lower, $substr)) return $key;
        }
        return null;
    }
}
