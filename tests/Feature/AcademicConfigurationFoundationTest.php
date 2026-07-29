<?php

namespace Tests\Feature;

use App\Models\AcademicYearConfiguration;
use App\Models\CalendarProfile;
use App\Models\Course;
use App\Models\CurriculumPackage;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\StandardsFramework;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\AcademicReferenceSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AcademicConfigurationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_seeders_are_idempotent_and_truthfully_labeled(): void
    {
        $this->seed(DatabaseSeeder::class);
        $firstCounts = [
            DB::table('education_providers')->count(),
            DB::table('standards_frameworks')->count(),
            DB::table('subjects')->count(),
            DB::table('grade_levels')->count(),
        ];
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($firstCounts, [
            DB::table('education_providers')->count(),
            DB::table('standards_frameworks')->count(),
            DB::table('subjects')->count(),
            DB::table('grade_levels')->count(),
        ]);
        $this->assertDatabaseHas('education_providers', ['short_name' => 'CFISD', 'tenant_id' => null, 'status' => 'active']);
        $this->assertDatabaseHas('standards_frameworks', ['short_name' => 'TEKS', 'tenant_id' => null, 'version_label' => 'unversioned']);
        $this->assertStringContainsString('not been imported', DB::table('standards_frameworks')->where('short_name', 'TEKS')->value('notes'));
        $this->assertSame(
            ['ELAR', 'MATH', 'SCI', 'SS', 'ART', 'MUSIC', 'PE', 'HEALTH', 'TECH', 'LANG', 'ELEC', 'OTHER'],
            DB::table('subjects')->orderBy('sort_order')->pluck('code')->all(),
        );
    }

    public function test_shared_records_are_visible_but_not_editable_and_custom_records_are_tenant_private(): void
    {
        $this->seed(AcademicReferenceSeeder::class);
        [$ownerA, $tenantA] = $this->tenantUser('owner', 'Tenant A');
        [$ownerB, $tenantB] = $this->tenantUser('owner', 'Tenant B');
        $sharedProvider = DB::table('education_providers')->whereNull('tenant_id')->first();

        $this->actingIn($ownerA, $tenantA)->get('/academic-setup/providers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Academic/Providers/Index')
                ->has('providers', 1)
                ->where('providers.0.is_shared', true));
        $this->actingIn($ownerA, $tenantA)->patch("/academic-setup/providers/{$sharedProvider->id}", $this->providerPayload())
            ->assertForbidden();
        $this->actingIn($ownerA, $tenantA)->post('/academic-setup/providers', $this->providerPayload(['name' => 'Tenant A Custom']))
            ->assertRedirect();
        $providerA = DB::table('education_providers')->where('tenant_id', $tenantA->id)->first();
        $this->assertSame('tenant:'.$tenantA->id, $providerA->ownership_key);

        $this->actingIn($ownerB, $tenantB)->get("/academic-setup/providers/{$providerA->id}/edit")->assertNotFound();
        $this->actingIn($ownerB, $tenantB)->get('/academic-setup/providers')
            ->assertInertia(fn (Assert $page) => $page->where('providers', fn ($providers) => collect($providers)->doesntContain('id', $providerA->id)));

        $this->actingIn($ownerA, $tenantA)->post('/academic-setup/subjects', [
            'name' => 'Robotics', 'code' => 'robotics', 'sort_order' => 50, 'status' => 'active',
        ])->assertRedirect();
        $this->actingIn($ownerB, $tenantB)->post('/academic-setup/subjects', [
            'name' => 'Robotics B', 'code' => 'robotics', 'sort_order' => 50, 'status' => 'active',
        ])->assertRedirect();
        $this->assertSame(2, DB::table('subjects')->where('code', 'ROBOTICS')->count());
    }

    public function test_calendar_profiles_events_and_summary_are_validated_and_tenant_scoped(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $this->schoolYear($tenant);

        $this->actingIn($owner, $tenant)->post('/academic-setup/calendars', $this->calendarPayload([
            'start_date' => '2027-05-28', 'end_date' => '2026-08-11',
        ]))->assertSessionHasErrors('end_date');
        $this->actingIn($owner, $tenant)->post('/academic-setup/calendars', $this->calendarPayload())
            ->assertRedirect();
        $calendar = DB::table('calendar_profiles')->where('tenant_id', $tenant->id)->first();

        $this->actingIn($owner, $tenant)->post("/academic-setup/calendars/{$calendar->id}/events", [
            'event_date' => '2026-11-23', 'end_date' => '2026-11-27', 'event_type' => 'break',
            'name' => 'Custom fall break', 'instructional_effect' => 'non_instructional',
            'status' => 'active',
        ])->assertRedirect();
        $this->actingIn($owner, $tenant)->post("/academic-setup/calendars/{$calendar->id}/events", [
            'event_date' => '2026-08-15', 'event_type' => 'instructional_makeup_day',
            'name' => 'Saturday makeup', 'instructional_effect' => 'instructional',
            'status' => 'active',
        ])->assertRedirect();
        $this->actingIn($owner, $tenant)->post("/academic-setup/calendars/{$calendar->id}/events", [
            'event_date' => '2026-09-02', 'end_date' => '2026-09-01', 'event_type' => 'other',
            'name' => 'Invalid', 'instructional_effect' => 'informational',
        ])->assertSessionHasErrors('end_date');

        $this->actingIn($owner, $tenant)->get("/academic-setup/calendars/{$calendar->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Academic/Calendars/Show')
                ->where('calendar.start_date', '2026-08-12')
                ->where('calendar.end_date', '2027-05-27')
                ->where('summaries.0.base_days', 207)
                ->where('summaries.0.removed_days', 5)
                ->where('summaries.0.added_days', 1)
                ->where('summaries.0.scheduled_days', 203));
    }

    public function test_courses_require_visible_subjects_and_valid_grade_ranges(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$ownerA, $tenantA] = $this->tenantUser('owner', 'Tenant A');
        [$ownerB, $tenantB, $membershipB] = $this->tenantUser('owner', 'Tenant B', true);
        $this->setContext($tenantB, $membershipB);
        $privateSubjectB = Subject::create(['name' => 'Private B', 'code' => 'B', 'sort_order' => 1, 'status' => 'active']);
        $grade4 = GradeLevel::query()->where('code', 'G4')->firstOrFail();
        $grade5 = GradeLevel::query()->where('code', 'G5')->firstOrFail();
        $sharedMath = DB::table('subjects')->where('code', 'MATH')->first();

        $this->actingIn($ownerA, $tenantA)->post('/academic-setup/courses', $this->coursePayload($privateSubjectB->id, $grade5->id, $grade5->id))
            ->assertSessionHasErrors('subject_id');
        $this->actingIn($ownerA, $tenantA)->post('/academic-setup/courses', $this->coursePayload($sharedMath->id, $grade5->id, $grade4->id))
            ->assertSessionHasErrors('maximum_grade_level_id');
        $this->actingIn($ownerA, $tenantA)->post('/academic-setup/courses', $this->coursePayload($sharedMath->id, $grade5->id, $grade5->id))
            ->assertRedirect();
        $this->assertDatabaseHas('courses', [
            'tenant_id' => $tenantA->id,
            'subject_id' => $sharedMath->id,
            'minimum_grade_level_id' => $grade5->id,
            'maximum_grade_level_id' => $grade5->id,
            'code' => 'G5-MATH',
        ]);
    }

    public function test_draft_package_mappings_persist_reorder_and_are_protected_after_activation(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$owner, $tenant, $membership] = $this->tenantUser('owner', 'Package Academy', true);
        $this->setContext($tenant, $membership);
        $subject = Subject::query()->where('code', 'MATH')->firstOrFail();
        $grade5 = GradeLevel::query()->where('code', 'G5')->firstOrFail();
        $course = Course::create($this->coursePayload($subject->id, $grade5->id, $grade5->id));

        $this->actingIn($owner, $tenant)->post('/academic-setup/curriculum', $this->packagePayload())
            ->assertRedirect();
        $package = DB::table('curriculum_packages')->where('tenant_id', $tenant->id)->first();
        $mappingPayload = ['course_id' => $course->id, 'grade_level_id' => $grade5->id, 'sort_order' => 2, 'required' => false];
        $this->actingIn($owner, $tenant)->post("/academic-setup/curriculum/{$package->id}/courses", $mappingPayload)
            ->assertRedirect();
        $this->actingIn($owner, $tenant)->post("/academic-setup/curriculum/{$package->id}/courses", $mappingPayload)
            ->assertSessionHasErrors('course_id');
        $mapping = DB::table('curriculum_package_courses')->where('curriculum_package_id', $package->id)->first();
        $this->actingIn($owner, $tenant)->patch("/academic-setup/curriculum/{$package->id}/courses/{$mapping->id}", [
            ...$mappingPayload, 'sort_order' => 1, 'required' => true,
        ])->assertRedirect();
        $this->assertDatabaseHas('curriculum_package_courses', ['id' => $mapping->id, 'sort_order' => 1, 'required' => true]);

        $this->actingIn($owner, $tenant)->patch("/academic-setup/curriculum/{$package->id}", $this->packagePayload(['status' => 'active']))
            ->assertRedirect();
        $this->actingIn($owner, $tenant)->delete("/academic-setup/curriculum/{$package->id}/courses/{$mapping->id}")
            ->assertUnprocessable();
        $this->assertDatabaseHas('curriculum_package_courses', ['id' => $mapping->id]);
        $this->actingIn($owner, $tenant)->patch("/academic-setup/courses/{$course->id}", [
            ...$this->coursePayload($subject->id, $grade5->id, $grade5->id),
            'name' => 'Silently changed history',
        ])->assertSessionHasErrors('name');
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'name' => 'Grade 5 Mathematics']);
    }

    public function test_configuration_activation_copy_and_historical_integrity(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$owner, $tenant, $membership] = $this->tenantUser('owner', 'Config Academy', true);
        $this->setContext($tenant, $membership);
        $year = $this->schoolYear($tenant);
        $nextYear = SchoolYear::create([
            'name' => '2027-2028', 'start_date' => '2027-08-11', 'end_date' => '2028-05-25',
            'timezone' => 'America/Chicago', 'status' => 'draft', 'instructional_day_target' => 180,
            'instructional_week_type' => 'five_day', 'instructional_weekdays' => [1, 2, 3, 4, 5],
        ]);
        $provider = EducationProvider::query()->firstOrFail();
        $framework = StandardsFramework::query()->firstOrFail();
        $subject = Subject::query()->where('code', 'MATH')->firstOrFail();
        $grade5 = GradeLevel::query()->where('code', 'G5')->firstOrFail();
        $calendar = CalendarProfile::create($this->calendarPayload());
        $course = Course::create($this->coursePayload($subject->id, $grade5->id, $grade5->id));
        $package = CurriculumPackage::create($this->packagePayload());
        $package->courseMappings()->create(['course_id' => $course->id, 'grade_level_id' => $grade5->id, 'sort_order' => 0, 'required' => true]);

        $payload = [
            'school_year_id' => $year->id, 'education_provider_id' => $provider->id,
            'calendar_profile_id' => $calendar->id, 'standards_framework_id' => $framework->id,
            'curriculum_package_id' => $package->id, 'status' => 'active', 'notes' => 'Reviewed',
        ];
        $this->actingIn($owner, $tenant)->post('/academic-setup/configuration', $payload)->assertRedirect();
        $configuration = DB::table('academic_year_configurations')->where('school_year_id', $year->id)->first();
        $this->assertSame('active', $configuration->status);
        $this->assertNotNull($configuration->configured_at);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'academic-configuration.created']);

        $this->actingIn($owner, $tenant)->post('/academic-setup/configuration/copy', [
            'source_school_year_id' => $year->id, 'target_school_year_id' => $nextYear->id,
        ])->assertRedirect();
        $copy = DB::table('academic_year_configurations')->where('school_year_id', $nextYear->id)->first();
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->calendar_profile_id);
        $this->assertSame($configuration->education_provider_id, $copy->education_provider_id);
        $this->assertSame(0, DB::table('student_enrollments')->where('school_year_id', $nextYear->id)->count());
        $this->assertSame('active', DB::table('academic_year_configurations')->where('id', $configuration->id)->value('status'));
    }

    public function test_cross_tenant_configuration_ids_roles_student_routes_and_fail_closed_scopes(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$ownerA, $tenantA] = $this->tenantUser('owner', 'Tenant A');
        [$ownerB, $tenantB, $membershipB] = $this->tenantUser('owner', 'Tenant B', true);
        $this->setContext($tenantB, $membershipB);
        $privateProviderB = EducationProvider::create($this->providerPayload(['name' => 'Private B']));
        $yearA = DB::table('school_years')->insertGetId([
            'tenant_id' => $tenantA->id, 'name' => '2026-2027', 'start_date' => '2026-08-12',
            'end_date' => '2027-05-27', 'timezone' => 'America/Chicago', 'status' => 'active',
            'instructional_day_target' => 1, 'instructional_week_type' => 'five_day',
            'instructional_weekdays' => json_encode([1, 2, 3, 4, 5]), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingIn($ownerA, $tenantA)->post('/academic-setup/configuration', [
            'school_year_id' => $yearA, 'education_provider_id' => $privateProviderB->id,
            'status' => 'draft',
        ])->assertSessionHasErrors('education_provider_id');

        [$parent, $parentTenant] = $this->tenantUser('parent', 'Parent Tenant');
        $this->actingIn($parent, $parentTenant)->get('/academic-setup')->assertOk();
        $this->actingIn($parent, $parentTenant)->post('/academic-setup/providers', $this->providerPayload())->assertForbidden();

        [$studentUser, $studentTenant, $studentMembership] = $this->tenantUser('student', 'Student Tenant', true);
        $this->setContext($studentTenant, $studentMembership);
        Student::create(['user_id' => $studentUser->id, 'first_name' => 'Student', 'last_name' => 'User', 'status' => 'active']);
        $this->actingIn($studentUser, $studentTenant)->get('/academic-setup')->assertForbidden();

        app()->forgetScopedInstances();
        $this->assertSame(0, EducationProvider::query()->count());
        $this->assertSame(0, AcademicYearConfiguration::query()->count());
    }

    private function tenantUser(string $role = 'owner', string $name = 'Academic Academy', bool $includeMembership = false): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create([
            'name' => $name, 'type' => 'homeschool_family', 'timezone' => 'America/Chicago',
            'locale' => 'en', 'status' => 'active',
        ]);
        $membership = TenantMembership::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active',
        ]);

        return $includeMembership ? [$user, $tenant, $membership] : [$user, $tenant];
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    }

    private function setContext(Tenant $tenant, TenantMembership $membership): void
    {
        app(TenantContext::class)->set($tenant, $membership);
    }

    private function schoolYear(Tenant $tenant): SchoolYear
    {
        $membership = TenantMembership::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->setContext($tenant, $membership);

        return SchoolYear::create([
            'name' => '2026-2027', 'start_date' => '2026-08-12', 'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => 1,
            'instructional_week_type' => 'five_day', 'instructional_weekdays' => [1, 2, 3, 4, 5],
        ]);
    }

    private function providerPayload(array $overrides = []): array
    {
        return [...[
            'name' => 'Custom Provider', 'short_name' => 'CUSTOM', 'provider_type' => 'custom',
            'state_or_region' => 'Texas', 'country_code' => 'US', 'website_url' => null,
            'status' => 'active', 'notes' => null,
        ], ...$overrides];
    }

    private function calendarPayload(array $overrides = []): array
    {
        return [...[
            'education_provider_id' => null, 'name' => 'Custom 2026-2027 Calendar',
            'academic_year_label' => '2026-2027', 'start_date' => '2026-08-12',
            'end_date' => '2027-05-27', 'timezone' => 'America/Chicago', 'status' => 'draft',
            'source_type' => 'tenant_custom', 'source_url' => null, 'source_version' => null, 'notes' => null,
        ], ...$overrides];
    }

    private function coursePayload(int $subjectId, int $minimumGradeId, int $maximumGradeId): array
    {
        return [
            'subject_id' => $subjectId, 'standards_framework_id' => null, 'education_provider_id' => null,
            'name' => 'Grade 5 Mathematics', 'code' => 'G5-MATH', 'description' => null,
            'minimum_grade_level_id' => $minimumGradeId, 'maximum_grade_level_id' => $maximumGradeId,
            'status' => 'draft',
        ];
    }

    private function packagePayload(array $overrides = []): array
    {
        return [...[
            'education_provider_id' => null, 'standards_framework_id' => null,
            'name' => 'Grade 5 Core', 'version_label' => '2026-2027', 'description' => null,
            'status' => 'draft', 'effective_start_date' => null, 'effective_end_date' => null,
            'source_url' => null,
        ], ...$overrides];
    }
}
