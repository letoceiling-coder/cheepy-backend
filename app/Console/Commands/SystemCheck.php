<?php

namespace App\Console\Commands;

use App\Models\ParserSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class SystemCheck extends Command
{
    protected $signature = 'system:check';
    protected $description = 'Validate env, database, redis, parser and proxy readiness';

    public function handle(): int
    {
        $checks = [];

        $broadcastConnection = (string) config('broadcasting.default');
        $reverbKey = (string) env('REVERB_APP_KEY', '');
        $reverbSecret = (string) env('REVERB_APP_SECRET', '');
        $reverbAppId = (string) env('REVERB_APP_ID', '');
        $hasReverbCredentials = $reverbKey !== '' && $reverbSecret !== '' && $reverbAppId !== '';

        $checks[] = ['Env', 'BROADCAST_CONNECTION', $broadcastConnection, in_array($broadcastConnection, ['log', 'reverb'], true) ? 'OK' : 'FAIL'];
        $checks[] = ['Env', 'REVERB credentials', $hasReverbCredentials ? 'configured' : 'missing (fallback=log)', 'OK'];

        $dbOk = false;
        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable $e) {
            // noop
        }
        $checks[] = ['Database', 'connection', $dbOk ? 'connected' : 'failed', $dbOk ? 'OK' : 'FAIL'];

        $redisOk = false;
        try {
            Redis::ping();
            $redisOk = true;
        } catch (\Throwable $e) {
            // noop
        }
        $checks[] = ['Redis', 'ping', $redisOk ? 'pong' : 'failed', $redisOk ? 'OK' : 'FAIL'];

        $queueOk = false;
        $queueInfo = 'unknown';
        try {
            $conn = Queue::connection(config('queue.default'));
            $queueInfo = sprintf(
                'parser=%d photos=%d',
                (int) $conn->size('parser'),
                (int) $conn->size('photos')
            );
            $queueOk = true;
        } catch (\Throwable $e) {
            $queueInfo = 'failed: ' . $e->getMessage();
        }
        $checks[] = ['Queue', 'connection', $queueInfo, $queueOk ? 'OK' : 'FAIL'];

        $settingsOk = false;
        $proxyInfo = 'n/a';
        try {
            $settings = ParserSetting::current();
            $settingsOk = true;
            $proxyInfo = $settings->proxy_enabled
                ? ((string) ($settings->proxy_url ?: 'missing'))
                : 'disabled';
            $checks[] = ['Parser', 'settings row', 'loaded', 'OK'];
            $checks[] = ['Parser', 'proxy', $proxyInfo, $settings->proxy_enabled && !$settings->proxy_url ? 'FAIL' : 'OK'];
            $checks[] = ['Parser', 'timeouts/delay', sprintf(
                'timeout=%ds delay=%d-%dms',
                (int) $settings->timeout_seconds,
                (int) $settings->request_delay_min,
                (int) $settings->request_delay_max
            ), 'OK'];
        } catch (\Throwable $e) {
            $checks[] = ['Parser', 'settings row', 'failed: ' . $e->getMessage(), 'FAIL'];
        }

        $this->table(['Group', 'Check', 'Value', 'Status'], $checks);

        $hasFail = collect($checks)->contains(fn (array $row): bool => $row[3] === 'FAIL');

        if ($hasFail) {
            $this->error('System check failed.');
            return 1;
        }

        $this->info('System check passed.');
        return 0;
    }
}
