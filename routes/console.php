<?php

use App\Console\Commands\RunParserJobCommand;
use App\Console\Commands\RebuildAttributes;
use App\Console\Commands\AuditAttributes;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
    \App\Jobs\DownloadPhotosJob::dispatch(100)->onQueue('photos');
})->name('scheduler-download-photos-batch')
    ->hourly()
    ->withoutOverlapping(60);

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
