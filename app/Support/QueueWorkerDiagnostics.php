<?php

namespace App\Support;

/**
 * Count Laravel `queue:work` processes per queue + supervisor program lines.
 * Supervisor names vary by deployment; ps-based scan is the reliable fallback.
 */
final class QueueWorkerDiagnostics
{
    /**
     * @return array{parser_workers: int, photo_workers: int, supervisor_parser: int, supervisor_photo: int, ps_parser: int, ps_photo: int}
     */
    public static function snapshot(): array
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('shell_exec')) {
            return [
                'parser_workers' => 0,
                'photo_workers' => 0,
                'supervisor_parser' => 0,
                'supervisor_photo' => 0,
                'ps_parser' => 0,
                'ps_photo' => 0,
            ];
        }

        $psOut = (string) (@shell_exec('ps aux 2>/dev/null') ?? '');
        $psParser = self::countQueueWorkForQueueName($psOut, 'parser');
        $psPhoto = self::countQueueWorkForQueueName($psOut, 'photos');

        $supOut = (string) (@shell_exec('supervisorctl status 2>/dev/null') ?? '');
        // Production often uses [program:parser-worker-photos] — "photo-worker" is not a substring of that name.
        // Also "parser-worker" matches "parser-worker-photos" — exclude photos group from parser count.
        $supParser = self::countSupervisorRunningLines($supOut, ['parser-worker'], ['parser-worker-photos']);
        $supPhoto = self::countSupervisorRunningLines($supOut, ['parser-worker-photos', 'photo-worker']);

        return [
            'parser_workers' => max($psParser, $supParser),
            'photo_workers' => max($psPhoto, $supPhoto),
            'supervisor_parser' => $supParser,
            'supervisor_photo' => $supPhoto,
            'ps_parser' => $psParser,
            'ps_photo' => $psPhoto,
        ];
    }

    /**
     * @param  array<int, string>  $includeSubstrings  e.g. program group name fragment
     * @param  array<int, string>  $excludeSubstrings  skip line if it contains any (after a positive include match)
     */
    private static function countSupervisorRunningLines(
        string $supervisorOutput,
        array $includeSubstrings,
        array $excludeSubstrings = [],
    ): int {
        if ($supervisorOutput === '') {
            return 0;
        }

        $n = 0;
        foreach (preg_split('/\R/', $supervisorOutput) as $line) {
            if (! str_contains($line, 'RUNNING')) {
                continue;
            }
            foreach ($includeSubstrings as $sub) {
                if (! str_contains($line, $sub)) {
                    continue;
                }
                $skip = false;
                foreach ($excludeSubstrings as $ex) {
                    if (str_contains($line, $ex)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }
                $n++;
                break;
            }
        }

        return $n;
    }

    private static function countQueueWorkForQueueName(string $psOutput, string $queueName): int
    {
        if ($psOutput === '') {
            return 0;
        }

        $n = 0;
        foreach (preg_split('/\R/', $psOutput) as $line) {
            if (! str_contains($line, 'artisan queue:work')) {
                continue;
            }
            if (! preg_match('/--queue=([^\s]+)/', $line, $m)) {
                continue;
            }
            $list = $m[1];
            foreach (explode(',', $list) as $q) {
                if (trim($q) === $queueName) {
                    $n++;
                    break;
                }
            }
        }

        return $n;
    }
}
