<?php

namespace App\Console\Commands;

use App\Models\ParserJob;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class ParserStatus extends Command
{
    protected $signature = 'parser:status';
    protected $description = 'Display parser status: running, daemon, queue, workers, products';

    public function handle(): int
    {
        $running = ParserJob::whereIn('status', ['running', 'pending'])->latest()->first();
        $daemonEnabled = (bool) Setting::get('parser_daemon_enabled', false);

        $parserSize = 0;
        $photosSize = 0;
        try {
            $conn = Queue::connection(config('queue.default'));
            $parserSize = $conn->size('parser');
            $photosSize = $conn->size('photos');
        } catch (\Throwable $e) {
            $this->warn('Queue: ' . $e->getMessage());
        }

        $workers = 0;
        $out = [];
        if (function_exists('exec')) {
            exec('ps aux 2>/dev/null | grep -c "queue:work" 2>/dev/null', $out);
            $workers = (int) trim($out[0] ?? '0');
        }

        $productsTotal = Product::count();

        $this->line('Parser running: ' . ($running ? "yes (job #{$running->id})" : 'no'));
        $this->line('Daemon enabled: ' . ($daemonEnabled ? 'yes' : 'no'));
        $this->line('Queue parser: ' . $parserSize);
        $this->line('Queue photos: ' . $photosSize);
        $this->line('Queue workers: ' . $workers);
        $this->line('Products total: ' . $productsTotal);

        if ($running) {
            $this->line("  Current: {$running->current_action}");
            $this->line("  Parsed: {$running->parsed_categories}/{$running->total_categories} categories, {$running->saved_products} products saved");
        }

        return 0;
    }
}
