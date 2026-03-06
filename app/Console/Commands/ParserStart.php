<?php

namespace App\Console\Commands;

use App\Jobs\RunParserJob;
use App\Models\ParserJob;
use Illuminate\Console\Command;

class ParserStart extends Command
{
    protected $signature = 'parser:start {--type=full : full|category|menu_only} {--category= : Category slug for type=category}';
    protected $description = 'Start a parser run';

    public function handle(): int
    {
        $type = $this->option('type') ?: 'full';
        $categorySlug = $this->option('category');

        $running = ParserJob::whereIn('status', ['running', 'pending'])->first();
        if ($running) {
            $this->warn("Parser already running (job #{$running->id}). Use parser:stop first.");
            return 1;
        }

        $options = [];
        if ($type === 'category' && $categorySlug) {
            $options['category_slug'] = $categorySlug;
        }

        $job = ParserJob::create([
            'type' => $type,
            'options' => $options,
            'status' => 'pending',
        ]);

        RunParserJob::dispatch($job->id);
        $this->info("Parser job #{$job->id} started (type={$type}).");
        return 0;
    }
}
