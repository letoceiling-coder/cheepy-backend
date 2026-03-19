<?php

namespace App\Console\Commands;

use App\Jobs\AutoMapDonorCategoryJob;
use App\Models\DonorCategory;
use App\Support\AutoMappingCommandContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AutoMapCategoriesCommand extends Command
{
    protected $signature = 'catalog:auto-map-categories
                            {--sync : Run inline instead of queue}
                            {--chunk=100 : Donor categories per chunk}
                            {--force : Ignore idempotency; always write logs when process reaches writeLog}
                            {--only-unmapped : Only donor categories without category_mapping}
                            {--only-low-confidence : Only donors with mapping and confidence < 90}';

    protected $description = 'Dispatch auto-mapping job for donor categories (evolvable engine)';

    public function handle(): int
    {
        $sync = (bool) $this->option('sync');
        $chunk = max(1, (int) $this->option('chunk'));
        $force = (bool) $this->option('force');
        $onlyUnmapped = (bool) $this->option('only-unmapped');
        $onlyLowConfidence = (bool) $this->option('only-low-confidence');

        if ($onlyUnmapped && $onlyLowConfidence) {
            $this->warn('Both --only-unmapped and --only-low-confidence: result is usually empty (AND). Prefer one filter.');
        }

        $count = 0;
        $since = now();

        if ($sync) {
            $this->laravel->instance(AutoMappingCommandContext::class, new AutoMappingCommandContext($force));
        }

        $query = DonorCategory::query()->orderBy('id')->select('id');
        $this->applyFilters($query, $onlyUnmapped, $onlyLowConfidence);

        $query->chunkById($chunk, function ($rows) use ($sync, $force, &$count) {
            foreach ($rows as $row) {
                if ($sync) {
                    AutoMapDonorCategoryJob::dispatchSync($row->id, $force);
                } else {
                    AutoMapDonorCategoryJob::dispatch($row->id, $force);
                }
                $count++;
            }
        });

        $filterNote = '';
        if ($onlyUnmapped) {
            $filterNote .= ' [only-unmapped]';
        }
        if ($onlyLowConfidence) {
            $filterNote .= ' [only-low-confidence]';
        }
        if ($force) {
            $filterNote .= ' [force]';
        }

        $this->info("Processed (dispatched) {$count} donor categories in chunks of {$chunk}{$filterNote}" . ($sync ? ' (sync).' : '.'));

        if ($sync) {
            /** @var AutoMappingCommandContext $ctx */
            $ctx = $this->laravel->make(AutoMappingCommandContext::class);
            $this->line('Skipped duplicate logs (idempotent): ' . $ctx->skippedDuplicateLogs);
            $this->line('Reprocessed (process() calls): ' . $ctx->reprocessedCount);
            $this->line('Logs written this run: ' . $ctx->logsWrittenCount);
            $this->line('Forced mode donors processed: ' . ($force ? $count : 0));

            $rows = DB::table('auto_mapping_logs')
                ->where('created_at', '>=', $since)
                ->selectRaw('decision, COUNT(*) as c')
                ->groupBy('decision')
                ->get();

            $m = $rows->pluck('c', 'decision')->map(fn ($v) => (int) $v)->all();

            $this->line('--- Metrics (new auto_mapping_logs rows this run) ---');
            $this->line('  auto_applied: ' . ($m['auto_applied'] ?? 0));
            $this->line('  manual_required: ' . ($m['manual_required'] ?? 0));
            $this->line('  rejected: ' . ($m['rejected'] ?? 0));
            $this->line('  manual_override: ' . ($m['manual_override'] ?? 0));
            foreach ($m as $decision => $c) {
                if (! in_array($decision, ['auto_applied', 'manual_required', 'rejected', 'manual_override'], true)) {
                    $this->line("  {$decision}: {$c}");
                }
            }
            $newLogRows = (int) DB::table('auto_mapping_logs')->where('created_at', '>=', $since)->count();
            $this->line('  total_new_log_rows: ' . $newLogRows);
            $this->line('  total_donors_processed: ' . $count);
        } else {
            $this->warn('Async mode: per-run metrics are not available (jobs pending). Use --sync for metrics.');
        }

        return self::SUCCESS;
    }

    private function applyFilters(Builder $query, bool $onlyUnmapped, bool $onlyLowConfidence): void
    {
        if ($onlyUnmapped) {
            $query->whereDoesntHave('mapping');
        }
        if ($onlyLowConfidence) {
            $query->whereHas('mapping', function (Builder $q): void {
                $q->where('confidence', '<', 90);
            });
        }
    }
}
