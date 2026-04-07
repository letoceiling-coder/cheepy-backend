<?php

namespace App\Console\Commands;

use App\Jobs\RunParserJob;
use App\Models\ParserJob;
use App\Support\ParserJobOptions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ParserStart extends Command
{
    protected $signature = 'parser:start {--type=full : full|category|menu_only|seller} {--category= : Category slug for type=category} {--seller= : Seller slug for type=seller}';

    protected $description = 'Start a parser run (options always from ParserJobOptions / parser_settings + runtime snapshot)';

    public function handle(): int
    {
        $type = $this->option('type') ?: 'full';
        $categorySlug = $this->option('category');
        $sellerSlug = $this->option('seller');

        $running = ParserJob::whereIn('status', ['running', 'pending'])->first();
        if ($running) {
            $this->warn("Parser already running (job #{$running->id}). Use parser:stop first.");

            return 1;
        }

        $options = ParserJobOptions::buildFromSettings();
        if ($type === 'category' && $categorySlug) {
            $options['category_slug'] = (string) $categorySlug;
        }
        if ($type === 'seller' && $sellerSlug) {
            $options['seller_slug'] = (string) $sellerSlug;
        }

        ParserJobOptions::assertCategoriesForJob($type, $options);

        Log::critical('OPTIONS BEFORE CREATE', $options);

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
