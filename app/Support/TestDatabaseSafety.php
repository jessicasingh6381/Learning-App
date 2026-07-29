<?php

namespace App\Support;

use RuntimeException;

final class TestDatabaseSafety
{
    /**
     * Assert that destructive test helpers may run against the configured database.
     *
     * @param  array{driver?: mixed, database?: mixed}  $connection
     */
    public static function assertSafe(array $connection): void
    {
        $driver = $connection['driver'] ?? null;
        $database = $connection['database'] ?? null;

        if ($driver !== 'sqlite' || $database !== ':memory:') {
            $name = is_scalar($database) ? (string) $database : get_debug_type($database);

            throw new RuntimeException("Tests must use SQLite in memory; refusing database [{$name}].");
        }
    }
}
