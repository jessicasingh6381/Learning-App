<?php

namespace Tests;

use App\Support\TestDatabaseSafety;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Run the database guard after the application is booted but before Laravel
     * invokes RefreshDatabase, DatabaseMigrations, or DatabaseTruncation.
     */
    protected function setUpTraits()
    {
        $connectionName = config('database.default');
        TestDatabaseSafety::assertSafe((array) config("database.connections.{$connectionName}", []));

        return parent::setUpTraits();
    }
}
