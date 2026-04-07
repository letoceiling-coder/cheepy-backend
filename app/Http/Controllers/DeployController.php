<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class DeployController extends Controller
{
    public function deploy(Request $request): JsonResponse
    {
        $configuredKey = (string) config('app.deploy_key', '');
        if ($configuredKey === '') {
            Log::warning('Deploy rejected: DEPLOY_KEY not configured');

            return response()->json([
                'status' => 'fail',
                'message' => 'Deploy is not configured on this server',
            ], 503);
        }

        $provided = (string) $request->header('X-DEPLOY-KEY', '');
        if ($provided === '' || ! hash_equals($configuredKey, $provided)) {
            Log::warning('Deploy rejected: invalid or missing X-DEPLOY-KEY', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'status' => 'fail',
                'message' => 'Forbidden',
            ], 403);
        }

        $script = base_path('deploy_server.sh');
        if (! is_readable($script)) {
            Log::error('Deploy failed: deploy_server.sh missing', ['path' => $script]);

            return response()->json([
                'status' => 'fail',
                'duration' => 0.0,
                'exit_code' => -1,
                'output' => ['deploy_server.sh not found or not readable'],
            ], 500);
        }

        $started = microtime(true);

        try {
            $result = Process::timeout(3600)
                ->path(base_path())
                ->run(['bash', $script]);
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $started, 3);
            Log::error('Deploy process exception', [
                'exception' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'status' => 'fail',
                'duration' => $duration,
                'exit_code' => -1,
                'output' => $this->linesFromString($e->getMessage()),
            ], 500);
        }

        $duration = round(microtime(true) - $started, 3);
        $exitCode = $result->exitCode();
        $ok = $result->successful();

        $output = $this->mergeOutputLines($result->output(), $result->errorOutput());

        Log::info('Deploy finished', [
            'ip' => $request->ip(),
            'duration' => $duration,
            'exit_code' => $exitCode,
            'successful' => $ok,
        ]);

        return response()->json([
            'status' => $ok ? 'ok' : 'fail',
            'duration' => $duration,
            'exit_code' => $exitCode,
            'output' => $output,
        ], $ok ? 200 : 500);
    }

    /**
     * @return list<string>
     */
    private function mergeOutputLines(string $stdout, string $stderr): array
    {
        $lines = [];
        foreach ($this->linesFromString($stdout) as $line) {
            $lines[] = $line;
        }
        foreach ($this->linesFromString($stderr) as $line) {
            $lines[] = '[stderr] '.$line;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function linesFromString(string $blob): array
    {
        $blob = preg_replace("/\r\n|\r/", "\n", $blob);
        $parts = explode("\n", $blob);
        $out = [];
        foreach ($parts as $line) {
            $out[] = $line;
        }
        if (count($out) > 0 && end($out) === '') {
            array_pop($out);
        }

        return $out;
    }
}
