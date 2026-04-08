<?php

namespace App\Console\Commands;

use App\Models\CategoryMapping;
use App\Support\Testing\SafeApiTestingGuards;
use Database\Seeders\SafeApiTestingSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class TestingSafeApiRunCommand extends Command
{
    protected $signature = 'testing:safe-api-run
                            {--port= : Optional port 9000-9999; default = random free port in that range}';

    protected $description = 'Safe API E2E: migrate:fresh (testing DB) → seed → HTTP checks → cleanup';

    private ?Process $serveProcess = null;
    private ?int $servePort = null;
    private bool $shutdownHookRegistered = false;

    public function handle(): int
    {
        SafeApiTestingGuards::assertTestingDatabase();
        $this->registerShutdownHook();

        $port = $this->resolveEphemeralPort();

        try {
            $this->info('=== 1) migrate:fresh (testing DB) ===');
            Artisan::call('migrate:fresh', ['--force' => true]);
            $this->output->write(Artisan::output());

            $this->info('=== 2) SafeApiTestingSeeder ===');
            Artisan::call('db:seed', [
                '--class' => SafeApiTestingSeeder::class,
                '--force' => true,
            ]);
            $this->output->write(Artisan::output());

            $catalogId = (int) \App\Models\CatalogCategory::query()
                ->where('slug', 'api-safe-catalog-isolation')
                ->value('id');
            if ($catalogId < 1) {
                throw new \RuntimeException('Seeder did not create api-safe-catalog-isolation.');
            }

            $this->info("=== 3) API checks via http://127.0.0.1:{$port} (ephemeral serve) ===");
            $this->line("Chosen ephemeral port: {$port} (range 9000-9999, must be free)");
            $this->startServe($port);
            $this->waitForHealth($port);

            $base = "http://127.0.0.1:{$port}/api/v1";
            $login = Http::acceptJson()
                ->asJson()
                ->post("{$base}/auth/login", [
                    'email' => SafeApiTestingSeeder::TEST_ADMIN_EMAIL,
                    'password' => SafeApiTestingSeeder::TEST_ADMIN_PASSWORD,
                ]);

            if (! $login->successful() || empty($login->json('token'))) {
                throw new \RuntimeException('Login failed: HTTP '.$login->status().' '.$login->body());
            }

            $token = $login->json('token');
            $headers = [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ];

            $this->line('3a) Invalid donor_category_id → expect 422');
            $r1 = Http::withHeaders($headers)->asJson()->post("{$base}/admin/catalog/category-mapping", [
                'donor_category_id' => 999_999_999,
                'catalog_category_id' => $catalogId,
                'confidence' => 100,
            ]);
            if ($r1->status() !== 422) {
                throw new \RuntimeException("Expected 422, got {$r1->status()}: {$r1->body()}");
            }
            $this->line("    HTTP {$r1->status()} OK");

            $donorId = SafeApiTestingSeeder::ISOLATED_DONOR_ID;

            $this->line('3b) Double POST isolated donor → 201 then 200, one row');
            $r2 = Http::withHeaders($headers)->asJson()->post("{$base}/admin/catalog/category-mapping", [
                'donor_category_id' => $donorId,
                'catalog_category_id' => $catalogId,
                'confidence' => 88,
                'is_manual' => true,
            ]);
            if ($r2->status() !== 201) {
                throw new \RuntimeException("Expected 201, got {$r2->status()}: {$r2->body()}");
            }
            $this->line("    first HTTP {$r2->status()} OK");

            $r3 = Http::withHeaders($headers)->asJson()->post("{$base}/admin/catalog/category-mapping", [
                'donor_category_id' => $donorId,
                'catalog_category_id' => $catalogId,
                'confidence' => 99,
                'is_manual' => true,
            ]);
            if ($r3->status() !== 200) {
                throw new \RuntimeException("Expected 200, got {$r3->status()}: {$r3->body()}");
            }
            $this->line("    second HTTP {$r3->status()} OK");

            $rows = (int) CategoryMapping::query()->where('donor_category_id', $donorId)->count();
            if ($rows !== 1) {
                throw new \RuntimeException("Expected 1 mapping row for donor {$donorId}, got {$rows}.");
            }
            $this->line("    mapping_rows_for_isolated_donor={$rows} OK");

            $this->line('3c) Invalid reorder payload → expect 422');
            $r4 = Http::withHeaders($headers)->asJson()->patch("{$base}/admin/catalog/categories/reorder", [
                'invalid' => true,
            ]);
            if ($r4->status() !== 422) {
                throw new \RuntimeException("Expected 422, got {$r4->status()}: {$r4->body()}");
            }
            $this->line("    HTTP {$r4->status()} OK");

            $this->line('3d) No duplicate donor_category_id in category_mapping');
            $dupes = DB::select(
                'SELECT donor_category_id, COUNT(*) AS c FROM category_mapping GROUP BY donor_category_id HAVING c > 1'
            );
            if ($dupes !== []) {
                throw new \RuntimeException('Duplicate donor_category_id groups: '.json_encode($dupes));
            }
            $this->line('    [] OK');
        } finally {
            $this->stopServe();
        }

        $this->info('=== 4) testing:safe-api-cleanup ===');
        Artisan::call('testing:safe-api-cleanup');
        $this->output->write(Artisan::output());

        $this->info('testing:safe-api-run completed successfully.');

        return self::SUCCESS;
    }

    private function resolveEphemeralPort(): int
    {
        $opt = $this->option('port');
        if ($opt !== null && $opt !== '') {
            $port = (int) $opt;
            if ($port < 9000 || $port > 9999) {
                throw new \RuntimeException("Port must be between 9000 and 9999, got [{$port}].");
            }
            if (! $this->isTcpPortFree('127.0.0.1', $port)) {
                throw new \RuntimeException("Port [{$port}] is not free on 127.0.0.1.");
            }

            return $port;
        }

        for ($tries = 0; $tries < 80; $tries++) {
            $port = random_int(9000, 9999);
            if ($this->isTcpPortFree('127.0.0.1', $port)) {
                return $port;
            }
        }

        throw new \RuntimeException('Could not find a free TCP port in 9000-9999 after 80 attempts.');
    }

    /**
     * True if we can bind to the port (nothing else listening on host:port).
     */
    private function isTcpPortFree(string $host, int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    private function startServe(int $port): void
    {
        $this->servePort = $port;
        $this->serveProcess = new Process(
            [PHP_BINARY, base_path('artisan'), 'serve', '--env=testing', '--host=127.0.0.1', '--port='.(string) $port],
            base_path(),
            null,
            null,
            null
        );
        $this->serveProcess->start();
    }

    private function registerShutdownHook(): void
    {
        if ($this->shutdownHookRegistered) {
            return;
        }

        $this->shutdownHookRegistered = true;

        register_shutdown_function(function (): void {
            // Final safety net for fatal errors / abrupt termination.
            $this->stopServe();
        });
    }

    private function waitForHealth(int $port): void
    {
        $maxAttempts = 10;
        $url = "http://127.0.0.1:{$port}/up";
        $this->line("Waiting for server (up to {$maxAttempts} attempts): {$url}");

        for ($i = 1; $i <= $maxAttempts; $i++) {
            try {
                $r = Http::timeout(2)->get($url);
                if ($r->successful()) {
                    $this->line("    [ready] attempt {$i}/{$maxAttempts} → HTTP {$r->status()}");

                    return;
                }
                $this->line("    [wait]  attempt {$i}/{$maxAttempts} → HTTP {$r->status()}");
            } catch (\Throwable $e) {
                $this->line("    [wait]  attempt {$i}/{$maxAttempts} → {$e->getMessage()}");
            }
            if ($i < $maxAttempts) {
                usleep(300_000);
            }
        }

        throw new \RuntimeException("Ephemeral server did not respond after {$maxAttempts} attempts: {$url}");
    }

    private function stopServe(): void
    {
        if ($this->serveProcess !== null && $this->serveProcess->isRunning()) {
            $this->serveProcess->stop(5, defined('SIGTERM') ? SIGTERM : null);
        }
        if ($this->serveProcess !== null && $this->serveProcess->isRunning()) {
            $this->serveProcess->stop(2, defined('SIGKILL') ? SIGKILL : null);
        }

        // Laravel serve can leave detached php -S listener. Kill by port as final safety net.
        if ($this->servePort !== null) {
            $this->killListenerByPort($this->servePort);
        }

        $this->serveProcess = null;
        $this->servePort = null;
    }

    private function killListenerByPort(int $port): void
    {
        $output = @shell_exec('ss -ltnp 2>/dev/null');
        if (! is_string($output) || $output === '') {
            return;
        }

        if (! preg_match_all('/127\\.0\\.0\\.1:'.preg_quote((string) $port, '/').'.*pid=(\\d+)/', $output, $m)) {
            return;
        }

        $pids = array_unique(array_map('intval', $m[1]));
        foreach ($pids as $pid) {
            if ($pid <= 1 || $pid === getmypid()) {
                continue;
            }
            if (function_exists('posix_kill')) {
                @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
                usleep(150_000);
                @posix_kill($pid, defined('SIGKILL') ? SIGKILL : 9);
            } else {
                @shell_exec('kill -TERM '.(int) $pid.' 2>/dev/null');
                usleep(150_000);
                @shell_exec('kill -KILL '.(int) $pid.' 2>/dev/null');
            }
        }
    }
}
