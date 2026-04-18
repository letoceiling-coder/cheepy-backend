<?php

namespace App\Support;

/**
 * Best-effort Reverb / WebSocket server reachability for admin dashboards.
 * Avoids false "stopped" when Reverb listens on ::1 or the process line is "artisan reverb:start".
 */
final class ReverbProcessProbe
{
    public static function websocketStatus(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'stopped';
        }

        try {
            $port = (int) (config('reverb.servers.reverb.port') ?? env('REVERB_SERVER_PORT', 8080));
            if ($port < 1 || $port > 65535) {
                return 'stopped';
            }

            foreach (['127.0.0.1', '::1'] as $host) {
                $fp = @fsockopen($host, $port, $errno, $errstr, 2);
                if ($fp !== false) {
                    fclose($fp);

                    return 'running';
                }
            }

            if (self::shellExecAllowed()) {
                $cmds = [
                    "ps aux 2>/dev/null | grep -E '[p]hp.*artisan reverb:start'",
                    "ps aux 2>/dev/null | grep -E '[p]hp.*reverb:start'",
                ];
                foreach ($cmds as $cmd) {
                    $out = trim((string) (@shell_exec($cmd) ?? ''));
                    if ($out !== '') {
                        return 'running';
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return 'stopped';
    }

    private static function shellExecAllowed(): bool
    {
        if (! function_exists('shell_exec')) {
            return false;
        }
        $raw = (string) ini_get('disable_functions');
        if ($raw === '') {
            return true;
        }
        $disabled = array_filter(array_map('trim', explode(',', $raw)));

        return ! in_array('shell_exec', $disabled, true);
    }
}
