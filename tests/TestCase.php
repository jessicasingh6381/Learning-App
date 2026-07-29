<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = DB::connection();
        $database = (string) $connection->getDatabaseName();
        if ($connection->getDriverName() !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException("Tests must use SQLite in memory; refusing database [{$database}].");
        }
    }
}
