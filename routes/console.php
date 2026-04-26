<?php

use App\Console\Commands\RunParserJobCommand;
use App\Console\Commands\RebuildAttributes;
use App\Console\Commands\AuditAttributes;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Parser failsafe: restart if idle + empty queue (every 5 min)
Schedule::command('parser:watchdog', ['--idle-minutes' => 10])
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Auto-recovery for PAUSED_NETWORK parser state
Schedule::command('parser:network-recover')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Parser lock heartbeat: refresh TTL every 30s while parser runs
Schedule::command('parser:lock-heartbeat')
    ->everyThirtySeconds();

// Full parser run every 6 hours (creates job + dispatches to queue)
Schedule::call(function () {
    $options = \App\Support\ParserJobOptions::buildFromSettings();
    $job = \App\Models\ParserJob::create([
        'type' => 'full',
        'status' => 'pending',
        'options' => $options,
    ]);
    \App\Jobs\RunParserJob::dispatch($job->id);
})->name('scheduler-full-parser')
    ->cron('0 */6 * * *')
    ->withoutOverlapping(120)
    ->appendOutputTo(storage_path('logs/scheduler-parser.log'));

// Photo download queue (process pending photos in batch).
// Guard: если в настройках download_photos=false — НЕ диспатчить. Раньше джоб стартовал
// всегда, пытался обработать photo_records pending и упирался в timeout (10 мин на батч),
// копил timeout'ы в failed_jobs. При выключенном даунлоаде это мёртвый код.
Schedule::call(function () {
    $settings = \App\Models\ParserSetting::current();
    if (! (bool) $settings->download_photos) {
        \Illuminate\Support\Facades\Log::info('scheduler-download-photos-batch skipped: download_photos=false');
        return;
    }
    $mode = (string) config('parser.photo_pipeline_mode', 'legacy');
    if ($mode === 'micro') {
        $maxQueueSize = max(100, (int) config('parser.max_photo_queue_size', 3000));
        $queueLen = (int) Redis::llen('queues:photos');
        if ((bool) config('parser.enable_priority_queues', false)) {
            $queueLen += (int) Redis::llen('queues:' . (string) config('parser.photos_queue_high', 'photos_high'));
            $queueLen += (int) Redis::llen('queues:' . (string) config('parser.photos_queue_normal', 'photos_normal'));
            $queueLen += (int) Redis::llen('queues:' . (string) config('parser.photos_queue_low', 'photos_low'));
        }
        if ($queueLen >= $maxQueueSize) {
            \Illuminate\Support\Facades\Log::warning('scheduler-download-photos-batch skipped: queue full', [
                'queue_len' => $queueLen,
                'max_photo_queue_size' => $maxQueueSize,
            ]);
            return;
        }

        $batchSize = max(1, (int) config('parser.micro_product_batch_size', 40));
        $ratePerSec = max(1, (int) config('parser.micro_dispatch_rate_per_sec', 20));
        $dispatched = 0;

        \App\Models\Product::query()
            ->where('photos_downloaded', false)
            ->where('photos_count', '>', 0)
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id')
            ->each(function (int $productId) use (&$dispatched, $ratePerSec, $maxQueueSize): void {
                $queueLen = (int) Redis::llen('queues:photos');
                if ((bool) config('parser.enable_priority_queues', false)) {
                    $queueLen += (int) Redis::llen('queues:' . (string) config('parser.photos_queue_high', 'photos_high'));
                    $queueLen += (int) Redis::llen('queues:' . (string) config('parser.photos_queue_normal', 'photos_normal'));
                    $queueLen += (int) Redis::llen('queues:' . (string) config('parser.photos_queue_low', 'photos_low'));
                }
                if ($queueLen >= $maxQueueSize) {
                    \Illuminate\Support\Facades\Log::warning('scheduler-download-photos-batch truncated: queue limit reached', [
                        'dispatched_products' => $dispatched,
                        'queue_len' => $queueLen,
                        'max_photo_queue_size' => $maxQueueSize,
                    ]);
                    return;
                }

                $delayMs = (int) floor(($dispatched * 1000) / $ratePerSec);
                \App\Jobs\DownloadProductPhotosJob::dispatch($productId)
                    ->onQueue('photos')
                    ->delay(now()->addMilliseconds($delayMs));
                $dispatched++;
            });
        return;
    }

    \App\Jobs\DownloadPhotosJob::dispatch(100)->onQueue('photos');
})->name('scheduler-download-photos-batch')
    ->hourly()
    ->withoutOverlapping(60);

// Photo pipeline telemetry: queue size, failed jobs, skip pressure.
Schedule::call(function () {
    $highQ = (string) config('parser.photos_queue_high', 'photos_high');
    $normalQ = (string) config('parser.photos_queue_normal', 'photos_normal');
    $lowQ = (string) config('parser.photos_queue_low', 'photos_low');
    \Illuminate\Support\Facades\Log::info('photo-pipeline-metrics', [
        'mode' => (string) config('parser.photo_pipeline_mode', 'legacy'),
        'photos_queue_size' => (int) Redis::llen('queues:photos'),
        'photos_high_queue_size' => (int) Redis::llen('queues:' . $highQ),
        'photos_normal_queue_size' => (int) Redis::llen('queues:' . $normalQ),
        'photos_low_queue_size' => (int) Redis::llen('queues:' . $lowQ),
        'failed_jobs_photos' => (int) DB::table('failed_jobs')->where('queue', 'photos')->count(),
        'failed_jobs_photos_high' => (int) DB::table('failed_jobs')->where('queue', $highQ)->count(),
        'failed_jobs_photos_normal' => (int) DB::table('failed_jobs')->where('queue', $normalQ)->count(),
        'failed_jobs_photos_low' => (int) DB::table('failed_jobs')->where('queue', $lowQ)->count(),
    ]);
})->name('photo-pipeline-metrics')
    ->everyFiveMinutes()
    ->withoutOverlapping(4);

// Cleanup and retention for photo pipeline artifacts (guarded by flag).
Schedule::job(new \App\Jobs\PhotoPipelineMaintenanceJob())
    ->name('photo-pipeline-maintenance')
    ->dailyAt('03:20')
    ->withoutOverlapping(120);

Schedule::command('queue:prune-failed', ['--hours' => 168])
    ->daily()
    ->at('03:00');

// Availability cleanup: hourly HEAD-probe для товаров с relevance_checked_at > 7 дней
// (или NULL). Работает маленькими пачками по 100, чтобы не нагружать донор.
// Страховочный механизм к availability-pass парсера — ловит товары, чьи категории
// давно не обходились.
Schedule::job(new \App\Jobs\CleanupUnavailableProductsJob(100))
    ->name('cleanup-unavailable-products')
    ->hourly()
    ->withoutOverlapping(60);
