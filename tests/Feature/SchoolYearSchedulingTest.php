<?php

namespace Tests\Feature;

use App\Models\SchoolYear;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchoolYearSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create([
            'name' => 'Schedule Test Academy',
            'type' => 'homeschool_family',
            'timezone' => 'America/Chicago',
            'locale' => 'en',
            'status' => 'active',
        ]);
        TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        return [$user, $tenant];
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id]);
    }

    /**
     * @param  array<int, int>  $submittedWeekdays
     * @param  array<int, int>  $storedWeekdays
     */
    #[DataProvider('validScheduleProvider')]
    public function test_schedule_types_save_normalized_authoritative_weekdays(
        string $type,
        array $submittedWeekdays,
        array $storedWeekdays,
    ): void {
        [$user, $tenant] = $this->owner();

        $this->actingIn($user, $tenant)
            ->post('/school-years', $this->payload([
                'instructional_week_type' => $type,
                'instructional_weekdays' => $submittedWeekdays,
            ]))
            ->assertRedirect(route('school-years.index'));

        $year = SchoolYear::query()->sole();

        $this->assertSame($type, $year->instructional_week_type);
        $this->assertSame($storedWeekdays, $year->instructional_weekdays);
        $this->assertNull($year->instructional_day_target);
    }

    /**
     * @return array<string, array{string, array<int, int>, array<int, int>}>
     */
    public static function validScheduleProvider(): array
    {
        return [
            'five-day preset' => [
                'five_day',
                [5, 1, 4, 2, 3],
                [1, 2, 3, 4, 5],
            ],
            'four-day preset' => [
                'four_day',
                [4, 2, 1, 3],
                [1, 2, 3, 4],
            ],
            'custom Tuesday through Friday' => [
                'custom',
                [5, 2, 4, 3],
                [2, 3, 4, 5],
            ],
            'custom non-contiguous' => [
                'custom',
                [5, 1, 3],
                [1, 3, 5],
            ],
        ];
    }

    #[DataProvider('invalidWeekdayProvider')]
    public function test_invalid_weekday_payloads_are_rejected(mixed $weekdays): void
    {
        [$user, $tenant] = $this->owner();

        $response = $this->actingIn($user, $tenant)
            ->post('/school-years', $this->payload([
                'instructional_week_type' => 'custom',
                'instructional_weekdays' => $weekdays,
            ]));

        $response->assertSessionHasErrors();
        $weekdayErrors = collect(session('errors')->getBag('default')->keys())
            ->filter(
                static fn (string $key): bool => str_starts_with(
                    $key,
                    'instructional_weekdays',
                ),
            );

        $this->assertNotEmpty($weekdayErrors);
        $this->assertDatabaseCount('school_years', 0);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidWeekdayProvider(): array
    {
        return [
            'empty list' => [[]],
            'weekday zero' => [[0]],
            'weekday eight' => [[8]],
            'numeric string' => [['1']],
            'weekday name' => [['Monday']],
            'duplicates' => [[1, 1, 2]],
            'nested data' => [[[1, 2]]],
            'not an array' => ['Monday'],
            'associative structure' => [['monday' => 1]],
        ];
    }

    public function test_inconsistent_preset_weekdays_are_rejected(): void
    {
        [$user, $tenant] = $this->owner();

        $this->actingIn($user, $tenant)
            ->post('/school-years', $this->payload([
                'instructional_week_type' => 'four_day',
                'instructional_weekdays' => [2, 3, 4, 5],
            ]))
            ->assertSessionHasErrors('instructional_week_type');
    }

    public function test_schedule_type_and_weekdays_are_required_and_allowlisted(): void
    {
        [$user, $tenant] = $this->owner();
        $missingSchedule = $this->payload();
        unset(
            $missingSchedule['instructional_week_type'],
            $missingSchedule['instructional_weekdays'],
        );

        $this->actingIn($user, $tenant)
            ->post('/school-years', $missingSchedule)
            ->assertSessionHasErrors([
                'instructional_week_type',
                'instructional_weekdays',
            ]);

        $this->actingIn($user, $tenant)
            ->post('/school-years', $this->payload([
                'instructional_week_type' => 'weekdays_only',
            ]))
            ->assertSessionHasErrors('instructional_week_type');
    }

    #[DataProvider('invalidTargetProvider')]
    public function test_invalid_instructional_day_targets_are_rejected(mixed $target): void
    {
        [$user, $tenant] = $this->owner();

        $this->actingIn($user, $tenant)
            ->post('/school-years', $this->payload([
                'instructional_day_target' => $target,
            ]))
            ->assertSessionHasErrors('instructional_day_target');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidTargetProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'decimal' => [180.5],
            'text' => ['many'],
            'above maximum' => [367],
        ];
    }

    public function test_index_serializes_backend_base_days_and_readable_weekday_label(): void
    {
        [$user, $tenant] = $this->owner();
        $tenant->schoolYears()->create([
            'name' => '2026-2027',
            'start_date' => '2026-08-12',
            'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago',
            'status' => 'draft',
            'instructional_week_type' => 'custom',
            'instructional_weekdays' => [1, 3, 5],
            'instructional_day_target' => 1,
        ]);

        $this->actingIn($user, $tenant)->get('/school-years')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schoolYears.0.start_date', '2026-08-12')
                ->where('schoolYears.0.end_date', '2027-05-27')
                ->where('schoolYears.0.instructional_weekday_label', 'Mon, Wed, Fri')
                ->where('schoolYears.0.base_instructional_days', 124)
                ->where('schoolYears.0.instructional_day_target', 1));
    }

    public function test_create_form_defaults_to_the_five_day_schedule(): void
    {
        [$user, $tenant] = $this->owner();

        $this->actingIn($user, $tenant)->get('/school-years/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('defaults.instructional_week_type', 'five_day')
                ->where('defaults.instructional_weekdays', [1, 2, 3, 4, 5]));
    }

    public function test_client_tenant_id_cannot_override_school_year_assignment(): void
    {
        [$user, $tenant] = $this->owner();
        $otherTenant = Tenant::create([
            'name' => 'Other Academy',
            'type' => 'homeschool_family',
            'timezone' => 'UTC',
            'locale' => 'en',
            'status' => 'active',
        ]);

        $this->actingIn($user, $tenant)
            ->post('/school-years', $this->payload([
                'tenant_id' => $otherTenant->id,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('school_years', [
            'tenant_id' => $tenant->id,
            'name' => '2026-2027',
        ]);
        $this->assertDatabaseMissing('school_years', [
            'tenant_id' => $otherTenant->id,
            'name' => '2026-2027',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => '2026-2027',
            'start_date' => '2026-08-12',
            'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago',
            'status' => 'draft',
            'instructional_week_type' => 'five_day',
            'instructional_weekdays' => [1, 2, 3, 4, 5],
            'instructional_day_target' => null,
        ], $overrides);
    }
}
