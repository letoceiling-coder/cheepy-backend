<?php

namespace App\Console\Commands;

use App\Jobs\ParserDaemonJob;
use App\Models\ParserSetting;
use App\Models\ParserState;
use App\Services\Parser\HttpClient;
use App\Services\Parser\ParserLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ParserNetworkRecover extends Command
{
    protected $signature = 'parser:network-recover';
    protected $description = 'Try to recover parser from PAUSED_NETWORK state';

    public function handle(): int
    {
        $state = ParserState::current();
        if ($state->status !== ParserState::STATUS_PAUSED_NETWORK) {
            return 0;
        }

        $settings = ParserSetting::current();
        $url = 'https://sadovodbaza.ru';
        $http = new HttpClient(
            timeoutSeconds: max(10, (int) ($settings->timeout_seconds ?? config('parser.timeout', 60))),
            retryCount: 1,
            delayMinMs: max(100, (int) ($settings->request_delay_min ?? config('parser.delay_min', 1500))),
            delayMaxMs: max(500, (int) ($settings->request_delay_max ?? config('parser.delay_max', 3000))),
        );

        try {
            $html = $http->get($url);
            if ($html === '') {
                throw new \RuntimeException('Empty HTML response');
            }
        } catch (\Throwable $e) {
            ParserLogger::write('network_error', 'Network recovery check failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return 1;
        }

        Cache::put('parser:network_timeout_streak', 0, now()->addMinutes(30));
        $state->update([
            'status' => ParserState::STATUS_RUNNING,
            'last_start' => now(),
        ]);
        ParserDaemonJob::dispatch()->onQueue('parser');
        ParserLogger::write('info', 'Parser recovered from PAUSED_NETWORK and resumed', ['url' => $url]);
        $this->info('Parser resumed');
        return 0;
    }
}
