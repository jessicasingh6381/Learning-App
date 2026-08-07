<?php

namespace Tests\Feature;

use App\Models\CalendarProfile;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\GradeLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ParentTeacherWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GradeLevelSeeder::class);
        CarbonImmutable::setTestNow('2026-08-01 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function adult(string $role = 'owner', string $academy = 'Cosmic Quest Academy'): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => $academy, 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);
        app(TenantContext::class)->set($tenant, $membership);

        return [$user, $tenant, $membership];
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    }

    public function test_home_and_calendar_are_derived_from_tenant_saved_data_without_mutation(): void
    {
        [$owner, $tenant] = $this->adult();
        $year = $tenant->schoolYears()->create([
            'name' => '2026-2027', 'start_date' => '2026-08-12', 'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => 1,
            'instructional_week_type' => 'five_day', 'instructional_weekdays' => [1, 2, 3, 4, 5],
        ]);
        $student = $tenant->students()->create(['first_name' => 'Kai', 'last_name' => 'Singh', 'status' => 'active']);
        $tenant->enrollments()->create(['student_id' => $student->id, 'school_year_id' => $year->id, 'grade_level_id' => GradeLevel::where('code', 'G5')->value('id'), 'enrollment_date' => '2026-08-12', 'status' => 'active']);
        $provider = EducationProvider::create(['name' => 'District Provider', 'provider_type' => 'district', 'status' => 'active']);
        $calendar = CalendarProfile::create(['education_provider_id' => $provider->id, 'name' => 'Saved Calendar', 'academic_year_label' => '2026-2027', 'start_date' => '2026-08-12', 'end_date' => '2027-05-27', 'timezone' => 'America/Chicago', 'status' => 'active', 'source_type' => 'manual']);
        $calendar->events()->create(['event_date' => '2026-08-17', 'event_type' => 'holiday', 'name' => 'Saved holiday', 'instructional_effect' => 'non_instructional', 'status' => 'active']);
        $calendar->events()->create(['event_date' => '2026-08-22', 'event_type' => 'instructional_makeup_day', 'name' => 'Saved makeup day', 'instructional_effect' => 'instructional', 'status' => 'active']);
        $year->academicConfiguration()->create(['education_provider_id' => $provider->id, 'calendar_profile_id' => $calendar->id, 'status' => 'draft']);
        $before = ['years' => $tenant->schoolYears()->count(), 'students' => $tenant->students()->count(), 'events' => $calendar->events()->count()];

        $this->actingIn($owner, $tenant)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workspace/Home')
            ->where('academy.name', 'Cosmic Quest Academy')
            ->where('schoolYear.start_date', '2026-08-12')
            ->where('schoolYear.instructional_day_target', 1)
            ->where('students.0.name', 'Kai Singh')
            ->where('students.0.enrollment.grade', 'Grade 5')
            ->where('today.date', '2026-08-01')
            ->where('today.status', 'before_year')
            ->has('upcomingEvents', 2));
        $this->actingIn($owner, $tenant)->get('/calendar')->assertInertia(fn (Assert $page) => $page
            ->component('Workspace/Calendar')
            ->where('calendar.profile.name', 'Saved Calendar')
            ->where('calendar.opening_month', '2026-08')
            ->where('calendar.current_date', '2026-08-01')
            ->where('calendar.source', null)
            ->has('calendar.profile.events', 2)
            ->where('calendar.profile.events.0.event_type', 'holiday')
            ->where('calendar.profile.events.1.instructional_effect', 'instructional')
            ->where('calendar.summary.removed_days', 1)
            ->where('calendar.summary.added_days', 1)
            ->where('calendar.target', 1)
            ->where('upcomingEvents.0.name', 'Saved holiday')
            ->where('upcomingEvents.1.name', 'Saved makeup day'));
        $this->actingIn($owner, $tenant)->get('/learning-plan?student_id='.$student->id)->assertInertia(fn (Assert $page) => $page
            ->component('Workspace/LearningPlan')
            ->where('selectedStudent.id', $student->id)
            ->where('selectedStudent.enrollment.grade', 'Grade 5')
            ->where('schoolYear.name', '2026-2027')
            ->where('learningPlan.provider', 'District Provider')
            ->where('learningPlan.calendar', 'Saved Calendar'));

        $this->assertSame($before, ['years' => $tenant->schoolYears()->count(), 'students' => $tenant->students()->count(), 'events' => $calendar->events()->count()]);
        $this->assertDatabaseHas('school_years', ['id' => $year->id, 'instructional_day_target' => 1, 'instructional_weekdays' => json_encode([1, 2, 3, 4, 5])]);
    }

    public function test_workspace_supports_partial_setup_and_honest_placeholders(): void
    {
        [$parent, $tenant] = $this->adult('parent');

        $this->actingIn($parent, $tenant)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Workspace/Home')->where('schoolYear', null)->where('students', [])->where('setup.completed', 0)
            ->where('auth.permissions', fn ($permissions) => $permissions->contains('workspace.view') && ! $permissions->contains('advanced-academic.view')));

        foreach (['assignments', 'gradebook', 'attendance', 'reports'] as $section) {
            $this->actingIn($parent, $tenant)->get("/workspace/{$section}")->assertInertia(fn (Assert $page) => $page
                ->component('Workspace/Placeholder')->where('section', ucfirst($section)));
        }
    }

    public function test_calendar_opening_month_is_clamped_to_the_active_school_year(): void
    {
        [$owner, $tenant] = $this->adult();
        $year = $tenant->schoolYears()->create([
            'name' => '2026-2027', 'start_date' => '2026-08-12', 'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => null,
            'instructional_week_type' => 'custom', 'instructional_weekdays' => [1, 3, 5],
        ]);

        CarbonImmutable::setTestNow('2026-07-10 12:00:00');
        $this->actingIn($owner, $tenant)->get('/calendar')->assertInertia(fn (Assert $page) => $page
            ->where('calendar.opening_month', '2026-08')
            ->where('calendar.profile', null)
            ->where('calendar.source', null)
            ->where('calendar.summary.base_days', 124)
            ->where('upcomingEvents', []));

        CarbonImmutable::setTestNow('2026-11-10 12:00:00');
        $this->actingIn($owner, $tenant)->get('/calendar')->assertInertia(fn (Assert $page) => $page
            ->where('calendar.opening_month', '2026-11'));

        CarbonImmutable::setTestNow('2027-06-10 12:00:00');
        $this->actingIn($owner, $tenant)->get('/calendar')->assertInertia(fn (Assert $page) => $page
            ->where('calendar.opening_month', '2027-05'));
    }

    public function test_calendar_exposes_assigned_source_metadata_without_a_schema_change(): void
    {
        [$owner, $tenant] = $this->adult();
        $year = $tenant->schoolYears()->create([
            'name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31',
            'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => null,
            'instructional_week_type' => 'five_day', 'instructional_weekdays' => [1, 2, 3, 4, 5],
        ]);
        $calendar = CalendarProfile::create([
            'name' => 'District Calendar', 'academic_year_label' => '2026-2027',
            'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'timezone' => 'America/Chicago',
            'status' => 'active', 'source_type' => 'official_website',
            'source_url' => 'https://district.example/calendar', 'source_version' => 'Board approved',
        ]);
        $year->academicConfiguration()->create(['calendar_profile_id' => $calendar->id, 'status' => 'draft']);

        $this->actingIn($owner, $tenant)->get('/calendar')->assertInertia(fn (Assert $page) => $page
            ->where('calendar.source.name', 'District Calendar')
            ->where('calendar.source.type', 'official_website')
            ->where('calendar.source.reference', 'https://district.example/calendar')
            ->where('calendar.source.version', 'Board approved'));
    }

    public function test_workspace_is_tenant_isolated_and_student_accounts_are_denied(): void
    {
        [$owner, $tenantA, $membershipA] = $this->adult('owner', 'Academy A');
        [, $tenantB, $membershipB] = $this->adult('owner', 'Academy B');
        app(TenantContext::class)->set($tenantA, $membershipA);
        $tenantA->students()->create(['first_name' => 'A', 'last_name' => 'Student', 'status' => 'active']);
        app(TenantContext::class)->set($tenantB, $membershipB);
        $tenantB->students()->create(['first_name' => 'B', 'last_name' => 'Student', 'status' => 'active']);
        $foreignYear = $tenantB->schoolYears()->create([
            'name' => 'Foreign year', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31',
            'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => null,
            'instructional_week_type' => 'five_day', 'instructional_weekdays' => [1, 2, 3, 4, 5],
        ]);
        $foreignCalendar = CalendarProfile::create([
            'name' => 'Foreign calendar', 'academic_year_label' => '2026-2027',
            'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'timezone' => 'America/Chicago',
            'status' => 'active', 'source_type' => 'manual',
        ]);
        $foreignCalendar->events()->create([
            'event_date' => '2026-08-10', 'event_type' => 'holiday', 'name' => 'Foreign holiday',
            'instructional_effect' => 'non_instructional', 'status' => 'active',
        ]);
        $foreignYear->academicConfiguration()->create(['calendar_profile_id' => $foreignCalendar->id, 'status' => 'draft']);

        $this->actingIn($owner, $tenantA)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('academy.name', 'Academy A')->has('students', 1)->where('students.0.name', 'A Student'));
        $this->actingIn($owner, $tenantA)->get('/calendar')->assertInertia(fn (Assert $page) => $page
            ->where('academy.name', 'Academy A')->where('schoolYear', null)->where('calendar.profile', null));

        $studentUser = User::factory()->create(['email' => null, 'username' => 'student.user']);
        TenantMembership::create(['tenant_id' => $tenantA->id, 'user_id' => $studentUser->id, 'role' => 'student', 'status' => 'active']);
        $tenantA->students()->create(['user_id' => $studentUser->id, 'student_access_enabled_at' => now(), 'first_name' => 'Portal', 'last_name' => 'Student', 'status' => 'active']);
        $this->actingIn($studentUser, $tenantA)->get('/dashboard')->assertForbidden();
        $this->actingIn($studentUser, $tenantA)->get('/calendar')->assertForbidden();
    }
}
