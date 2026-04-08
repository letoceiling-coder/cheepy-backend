<?php

namespace App\Support\Testing;

/**
 * Hard guards: safe API / isolated test flows must pass before touching the database.
 */
final class SafeApiTestingGuards
{
    public const REQUIRED_DATABASE = 'online_parser_siteaacess_testing';

    /**
     * @throws \RuntimeException
     */
    public static function assertTestingEnvironment(): void
    {
        if (! app()->environment('testing')) {
            throw new \RuntimeException(
                'HARD GUARD: APP_ENV must be "testing". Run: php artisan <command> --env=testing'
            );
        }
    }

    /**
     * @throws \RuntimeException
     */
    public static function assertTestingDatabase(): void
    {
        self::assertTestingEnvironment();

        $db = (string) config('database.connections.'.config('database.default').'.database');
        if ($db !== self::REQUIRED_DATABASE) {
            throw new \RuntimeException(
                'HARD GUARD: DB_DATABASE must be ['.self::REQUIRED_DATABASE."], got [{$db}]."
            );
        }
    }
}
