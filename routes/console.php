<?php

use App\Console\Commands\RunParserJobCommand;
use App\Console\Commands\RebuildAttributes;
use App\Console\Commands\AuditAttributes;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
