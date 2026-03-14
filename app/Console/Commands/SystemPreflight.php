<?php

namespace App\Console\Commands;

use App\Models\ParserSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class SystemPreflight extends Command
{
    protected $signature = 'system:preflight';
    protected $description = 'Pre-deploy checks for DB, Redis, proxy and donor access';

    public function handle(): int
    {
        $checks = [];

        $dbOk = false;
        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable $e) {
            // no-op
        }
        $checks[] = ['database', $dbOk ? 'ok' : 'failed'];

        $redisOk = false;
        try {
            Redis::ping();
            $redisOk = true;
        } catch (\Throwable $e) {
            // no-op
        }
        $checks[] = ['redis', $redisOk ? 'ok' : 'failed'];

        $proxyEnabled = (bool) config('parser.proxy_enabled', false);
        $proxyUrl = (string) config('parser.proxy_url', '');
        if ($dbOk) {
            try {
                $settings = ParserSetting::current();
                $proxyEnabled = (bool) ($settings->proxy_enabled ?? $proxyEnabled);
                $proxyUrl = (string) ($settings->proxy_url ?: $proxyUrl);
            } catch (\Throwable $e) {
                // keep config fallback
            }
        }

        $proxyOk = true;
        if ($proxyEnabled && $proxyUrl !== '') {
            try {
                Http::timeout(25)
                    ->withOptions([
                        'proxy' => $proxyUrl,
                        'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                    ])
                    ->get('https://sadovodbaza.ru')
                    ->throw();
                $proxyOk = true;
            } catch (\Throwable $e) {
                $proxyOk = false;
            }
        }
        $checks[] = ['proxy', $proxyEnabled ? ($proxyOk ? 'ok' : 'failed') : 'skipped'];

        $donorOk = false;
        try {
            Http::timeout(25)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get('https://sadovodbaza.ru')
                ->throw();
            $donorOk = true;
        } catch (\Throwable $e) {
            $donorOk = false;
        }
        $checks[] = ['sadovodbaza_access', $donorOk ? 'ok' : 'failed'];

        $this->table(['check', 'status'], $checks);

        $failed = collect($checks)->contains(fn (array $row): bool => $row[1] === 'failed');
        if ($failed) {
            $this->error('Preflight failed. Deployment aborted.');
            return 1;
        }

        $this->info('Preflight passed.');
        return 0;
    }
}
