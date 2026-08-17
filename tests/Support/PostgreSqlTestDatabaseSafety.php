<?php

namespace Tests\Support;

use RuntimeException;

final class PostgreSqlTestDatabaseSafety
{
    public const DATABASE = 'learning_app_postgresql_compatibility_test';

    /**
     * @param  array{driver?: mixed, database?: mixed, host?: mixed}  $connection
     */
    public static function assertSafe(array $connection, string $environment, bool $optedIn): void
    {
        $driver = $connection['driver'] ?? null;
        $database = $connection['database'] ?? null;
        $host = $connection['host'] ?? null;
        $safeHosts = ['127.0.0.1', 'localhost', 'postgres'];

        if (
            ! $optedIn
            || $environment !== 'testing'
            || $driver !== 'pgsql'
            || $database !== self::DATABASE
            || ! in_array($host, $safeHosts, true)
        ) {
            $name = is_scalar($database) ? (string) $database : get_debug_type($database);

            throw new RuntimeException("PostgreSQL compatibility tests refuse database [{$name}].");
        }
    }
}
