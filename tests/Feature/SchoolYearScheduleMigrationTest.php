<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchoolYearScheduleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_migration_backfills_an_existing_school_year_in_place(): void
    {
        $tenant = Tenant::create([
            'name' => 'Existing Academy',
            'type' => 'homeschool_family',
            'timezone' => 'America/Chicago',
            'locale' => 'en',
            'status' => 'active',
        ]);

        Schema::table('school_years', function (Blueprint $table) {
            $table->dropColumn([
                'instructional_week_type',
                'instructional_weekdays',
            ]);
        });

        DB::table('school_years')->insert([
            'id' => 41,
            'tenant_id' => $tenant->id,
            'name' => '2026-2027',
            'start_date' => '2026-08-12',
            'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago',
            'status' => 'draft',
            'instructional_day_target' => 1,
            'created_at' => '2026-07-29 12:00:00',
            'updated_at' => '2026-07-29 12:00:00',
        ]);

        $before = DB::table('school_years')->where('id', 41)->first();
        $migration = require database_path(
            'migrations/2026_07_29_000300_add_instructional_schedule_to_school_years.php',
        );

        $migration->up();

        $after = DB::table('school_years')->where('id', 41)->first();

        foreach ((array) $before as $column => $value) {
            $this->assertSame($value, $after->{$column});
        }

        $this->assertSame('five_day', $after->instructional_week_type);
        $this->assertSame(
            [1, 2, 3, 4, 5],
            json_decode($after->instructional_weekdays, true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertDatabaseCount('school_years', 1);
    }
}
