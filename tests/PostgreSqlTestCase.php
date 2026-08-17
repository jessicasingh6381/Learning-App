<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\PostgreSqlTestDatabaseSafety;

abstract class PostgreSqlTestCase extends BaseTestCase
{
    protected function setUpTraits()
    {
        $connectionName = config('database.default');
        $optedIn = filter_var(
            $_ENV['POSTGRESQL_COMPATIBILITY_TEST'] ?? getenv('POSTGRESQL_COMPATIBILITY_TEST'),
            FILTER_VALIDATE_BOOL,
        );

        PostgreSqlTestDatabaseSafety::assertSafe(
            (array) config("database.connections.{$connectionName}", []),
            app()->environment(),
            $optedIn,
        );

        return parent::setUpTraits();
    }
}
