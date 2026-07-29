<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\StudentEnrollment;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\GradeLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnrollmentCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GradeLevelSeeder::class);
    }

    private function owner(string $name = 'Enrollment Academy'): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create([
            'name' => $name,
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

    #[DataProvider('validEnrollmentDateProvider')]
    public function test_enrollment_creation_accepts_dates_within_the_school_year(string $date): void
    {
        [$user, $tenant] = $this->owner();
        $student = $tenant->students()->create([
            'first_name' => 'Kai',
            'last_name' => 'Learner',
            'status' => 'active',
        ]);
        $year = $tenant->schoolYears()->create([
            'name' => '2026-2027',
            'start_date' => '2026-08-12',
            'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago',
            'status' => 'active',
        ]);

        $this->actingIn($user, $tenant)->post('/enrollments', [
            'student_id' => $student->id,
            'school_year_id' => $year->id,
            'grade_level_id' => GradeLevel::where('code', 'G5')->value('id'),
            'enrollment_date' => $date,
            'status' => 'active',
        ])->assertRedirect();

        $enrollment = StudentEnrollment::query()->where([
            'student_id' => $student->id,
            'school_year_id' => $year->id,
        ])->sole();

        $this->assertSame($date, $enrollment->enrollment_date->format('Y-m-d'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validEnrollmentDateProvider(): array
    {
        return [
            'school-year start' => ['2026-08-12'],
            'later date' => ['2026-09-15'],
        ];
    }

    #[DataProvider('invalidEnrollmentDateProvider')]
    public function test_enrollment_creation_rejects_dates_outside_the_school_year(string $date): void
    {
        [$user, $tenant] = $this->owner();
        $student = $tenant->students()->create([
            'first_name' => 'Kai',
            'last_name' => 'Learner',
            'status' => 'active',
        ]);
        $year = $tenant->schoolYears()->create([
            'name' => '2026-2027',
            'start_date' => '2026-08-12',
            'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago',
            'status' => 'active',
        ]);

        $this->actingIn($user, $tenant)->post('/enrollments', [
            'student_id' => $student->id,
            'school_year_id' => $year->id,
            'grade_level_id' => GradeLevel::where('code', 'G5')->value('id'),
            'enrollment_date' => $date,
            'status' => 'active',
        ])->assertSessionHasErrors('enrollment_date');

        $this->assertDatabaseCount('student_enrollments', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidEnrollmentDateProvider(): array
    {
        return [
            'before start' => ['2026-08-11'],
            'after end' => ['2027-05-28'],
        ];
    }

    public function test_create_form_serializes_only_active_tenant_school_years_as_date_only_values(): void
    {
        [$user, $tenant] = $this->owner();
        [, $otherTenant] = $this->owner('Other Academy');
        $tenant->students()->create([
            'first_name' => 'Kai',
            'last_name' => 'Learner',
            'status' => 'active',
        ]);
        $visibleYear = $tenant->schoolYears()->create([
            'name' => 'Visible Year',
            'start_date' => '2026-08-12',
            'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago',
            'status' => 'draft',
        ]);
        $otherTenant->schoolYears()->create([
            'name' => 'Private Year',
            'start_date' => '2030-01-02',
            'end_date' => '2030-12-20',
            'timezone' => 'UTC',
            'status' => 'active',
        ]);

        $this->actingIn($user, $tenant)->get('/enrollments/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('schoolYears', 1)
                ->where('schoolYears.0', [
                    'id' => $visibleYear->id,
                    'name' => 'Visible Year',
                    'start_date' => '2026-08-12',
                    'end_date' => '2027-05-27',
                ])
                ->missing('schoolYears.1'));
    }

    public function test_cross_tenant_school_year_cannot_be_used_for_enrollment(): void
    {
        [$user, $tenant] = $this->owner();
        [, $otherTenant] = $this->owner('Other Academy');
        $student = $tenant->students()->create([
            'first_name' => 'Kai',
            'last_name' => 'Learner',
            'status' => 'active',
        ]);
        $privateYear = $otherTenant->schoolYears()->create([
            'name' => 'Private Year',
            'start_date' => '2026-08-12',
            'end_date' => '2027-05-27',
            'timezone' => 'UTC',
            'status' => 'active',
        ]);

        $this->actingIn($user, $tenant)->post('/enrollments', [
            'student_id' => $student->id,
            'school_year_id' => $privateYear->id,
            'grade_level_id' => GradeLevel::where('code', 'G5')->value('id'),
            'enrollment_date' => '2026-08-12',
            'status' => 'active',
        ])->assertSessionHasErrors('school_year_id');

        $this->assertDatabaseCount('student_enrollments', 0);
    }

    public function test_create_form_returns_old_input_without_replacing_the_submitted_date(): void
    {
        [$user, $tenant] = $this->owner();
        $student = $tenant->students()->create([
            'first_name' => 'Kai',
            'last_name' => 'Learner',
            'status' => 'active',
        ]);
        $year = $tenant->schoolYears()->create([
            'name' => '2026-2027',
            'start_date' => '2026-08-12',
            'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago',
            'status' => 'active',
        ]);

        $this->actingIn($user, $tenant)
            ->withSession([
                '_old_input' => [
                    'student_id' => (string) $student->id,
                    'school_year_id' => (string) $year->id,
                    'grade_level_id' => (string) GradeLevel::where('code', 'G5')->value('id'),
                    'enrollment_date' => '2026-09-15',
                    'completion_date' => null,
                    'status' => 'active',
                ],
            ])
            ->get('/enrollments/create')
            ->assertInertia(fn (Assert $page) => $page
                ->where('oldInput.school_year_id', $year->id)
                ->where('oldInput.enrollment_date', '2026-09-15')
                ->where('oldInput.status', 'active'));
    }
}
