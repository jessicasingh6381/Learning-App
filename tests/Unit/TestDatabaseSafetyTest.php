<?php

namespace Tests\Unit;

use App\Support\TestDatabaseSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TestDatabaseSafetyTest extends TestCase
{
    public function test_it_accepts_only_sqlite_in_memory(): void
    {
        TestDatabaseSafety::assertSafe(['driver' => 'sqlite', 'database' => ':memory:']);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeConnections')]
    public function test_it_rejects_persistent_or_malformed_connections(array $connection): void
    {
        $this->expectException(RuntimeException::class);
        TestDatabaseSafety::assertSafe($connection);
    }

    public static function unsafeConnections(): array
    {
        return [
            'development MariaDB' => [['driver' => 'mysql', 'database' => 'learning_app']],
            'other MySQL database' => [['driver' => 'mysql', 'database' => 'learning_app_test']],
            'file SQLite' => [['driver' => 'sqlite', 'database' => 'database/testing.sqlite']],
            'missing database' => [['driver' => 'sqlite']],
            'missing driver' => [['database' => ':memory:']],
            'empty configuration' => [[]],
        ];
    }
}
