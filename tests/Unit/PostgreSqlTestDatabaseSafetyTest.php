<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\PostgreSqlTestDatabaseSafety;

class PostgreSqlTestDatabaseSafetyTest extends TestCase
{
    public function test_it_accepts_only_the_opted_in_dedicated_local_postgresql_database(): void
    {
        PostgreSqlTestDatabaseSafety::assertSafe([
            'driver' => 'pgsql',
            'database' => PostgreSqlTestDatabaseSafety::DATABASE,
            'host' => '127.0.0.1',
        ], 'testing', true);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeConnections')]
    public function test_it_rejects_non_test_postgresql_connections(
        array $connection,
        string $environment,
        bool $optedIn,
    ): void {
        $this->expectException(RuntimeException::class);

        PostgreSqlTestDatabaseSafety::assertSafe($connection, $environment, $optedIn);
    }

    public static function unsafeConnections(): array
    {
        $safe = [
            'driver' => 'pgsql',
            'database' => PostgreSqlTestDatabaseSafety::DATABASE,
            'host' => '127.0.0.1',
        ];

        return [
            'no explicit opt in' => [$safe, 'testing', false],
            'production environment' => [$safe, 'production', true],
            'normal application database' => [[...$safe, 'database' => 'learning_app'], 'testing', true],
            'remote database host' => [[...$safe, 'host' => 'production.postgres.database.azure.com'], 'testing', true],
            'wrong driver' => [[...$safe, 'driver' => 'mysql'], 'testing', true],
        ];
    }
}
