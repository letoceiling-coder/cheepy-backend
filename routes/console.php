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
