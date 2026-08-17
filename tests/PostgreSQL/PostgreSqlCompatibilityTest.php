<?php

namespace Tests\PostgreSQL;

use App\Models\GradeLevel;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\PostgreSqlTestCase;

class PostgreSqlCompatibilityTest extends PostgreSqlTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_postgresql_16_runs_the_application_schema_and_portable_queries(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertStringStartsWith('16.', (string) DB::scalar('show server_version'));

        foreach (['users', 'tenants', 'academic_sources', 'standards', 'lessons', 'creative_writing_entries'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected migrated table [{$table}].");
        }

        $this->assertSame(15, GradeLevel::query()->count());
        $this->assertTrue(GradeLevel::query()->whereLike('name', '%gRaDe 5%')->exists());

        $tenant = Tenant::query()->create([
            'name' => 'PostgreSQL Compatibility Academy',
            'type' => 'homeschool_family',
            'timezone' => 'America/Chicago',
            'locale' => 'en',
            'status' => 'active',
            'settings' => ['database' => 'postgresql'],
        ]);

        $this->assertSame(['database' => 'postgresql'], $tenant->fresh()->settings);
    }
}
