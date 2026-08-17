<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AcademyMigrationEnvironment
{
    public const SOURCE = 'migration_source';

    public const TARGET = 'migration_target';

    /** @return array{0: ConnectionInterface, 1: ConnectionInterface, 2: string} */
    public function guardedConnections(): array
    {
        $source = DB::connection(self::SOURCE);
        $target = DB::connection(self::TARGET);

        if ($source->getDriverName() !== 'mysql') {
            throw new RuntimeException('Refusing to continue: migration_source must use the mysql driver.');
        }
        if ($target->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Refusing to continue: migration_target must use the pgsql driver.');
        }

        $sourceDatabase = (string) $source->getDatabaseName();
        $targetDatabase = (string) $target->getDatabaseName();
        if ($sourceDatabase === '' || $targetDatabase === '') {
            throw new RuntimeException('Both migration database names must be explicitly configured.');
        }
        if ($sourceDatabase === $targetDatabase && $this->sameHost()) {
            throw new RuntimeException('Refusing to continue: source and target resolve to the same host/database.');
        }

        $approved = array_values(array_filter(array_map(
            'trim', explode(',', (string) env('ACADEMY_MIGRATION_APPROVED_TARGETS', 'cosmic_academy'))
        )));
        if (! in_array($targetDatabase, $approved, true)) {
            throw new RuntimeException("Target database [{$targetDatabase}] is not explicitly approved.");
        }
        $targetHost = strtolower((string) config('database.connections.'.self::TARGET.'.host'));
        $approvedHosts = array_values(array_filter(array_map(
            fn (string $host) => strtolower(trim($host)),
            explode(',', (string) env(
                'ACADEMY_MIGRATION_APPROVED_TARGET_HOSTS',
                'cosmic-academy-pg.postgres.database.azure.com'
            ))
        )));
        if (! in_array($targetHost, $approvedHosts, true)) {
            throw new RuntimeException("Target host [{$targetHost}] is not explicitly approved.");
        }

        $version = (string) ($target->selectOne('SHOW server_version')->server_version ?? '');
        if (! preg_match('/^16(?:\\.|$)/', $version)) {
            throw new RuntimeException("Target must be PostgreSQL 16; server reported [{$version}].");
        }

        return [$source, $target, $targetDatabase];
    }

    private function sameHost(): bool
    {
        return strtolower((string) config('database.connections.'.self::SOURCE.'.host'))
            === strtolower((string) config('database.connections.'.self::TARGET.'.host'));
    }
}
