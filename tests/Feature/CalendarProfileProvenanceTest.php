<?php

namespace Tests\Feature;

use App\Models\AcademicSource;
use App\Models\CalendarProfile;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarProfileProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_detail_serializes_safe_direct_source_and_managed_sources_separately(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $calendar = $this->calendar($year, [
            'source_url' => 'https://calendar.example.edu/district/2026',
            'source_version' => 'Board approved 2026-01-15',
        ]);
        $linked = $this->source($year, ['title' => 'Linked PDF']);
        $linked->links()->create(['link_type' => 'calendar_profile', 'link_id' => $calendar->id]);
        $suggested = $this->source($year, ['title' => 'Suggested PDF']);

        $this->actingIn($owner, $tenant)->get("/academic-setup/calendars/{$calendar->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Academic/Calendars/Show')
                ->where('sourceWebsite.url', 'https://calendar.example.edu/district/2026')
                ->where('sourceWebsite.domain', 'calendar.example.edu')
                ->where('calendar.source_version', 'Board approved 2026-01-15')
                ->missing('calendar.source_url')
                ->missing('calendar.ownership_key')
                ->missing('calendar.tenant_id')
                ->where('linkedSources.0.id', $linked->id)
                ->where('linkedSources.0.source_kind', 'upload')
                ->where('linkedSources.0.source_category', 'calendar')
                ->where('linkedSources.0.authority_level', 'official_provider')
                ->where('suggestedSources.0.id', $suggested->id));
    }

    public function test_missing_or_legacy_unsafe_source_url_is_never_exposed_as_a_link(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $missing = $this->calendar($year);
        $unsafe = $this->calendar($year, ['name' => 'Legacy unsafe', 'source_url' => 'javascript:alert(1)']);

        $this->actingIn($owner, $tenant)->get("/academic-setup/calendars/{$missing->id}")
            ->assertInertia(fn (Assert $page) => $page->where('sourceWebsite', null)->where('calendar.source_version', null));
        $this->actingIn($owner, $tenant)->get("/academic-setup/calendars/{$unsafe->id}")
            ->assertInertia(fn (Assert $page) => $page->where('sourceWebsite', null)->missing('calendar.source_url'));
    }

    public function test_calendar_source_url_validation_rejects_unsafe_schemes_and_credentials(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $this->schoolYear($owner, $tenant);

        foreach (['javascript:alert(1)', 'data:text/html,test', 'file:///tmp/calendar.pdf', 'https://user:secret@example.edu/calendar'] as $url) {
            $this->actingIn($owner, $tenant)->post('/academic-setup/calendars', $this->calendarPayload(['source_url' => $url]))
                ->assertSessionHasErrors('source_url');
        }

        $this->actingIn($owner, $tenant)->post('/academic-setup/calendars', $this->calendarPayload([
            'source_url' => 'https://www.example.edu/calendar',
        ]))->assertRedirect();
        $this->assertDatabaseHas('calendar_profiles', ['source_url' => 'https://www.example.edu/calendar']);
        $this->assertDatabaseCount('academic_sources', 0);
        $this->assertDatabaseCount('academic_source_links', 0);
    }

    public function test_linking_and_url_updates_are_independent_and_leave_calendar_calculations_unchanged(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $calendar = $this->calendar($year, ['source_url' => 'https://www.example.edu/original']);
        $source = $this->source($year);

        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/links", [
            'link_type' => 'calendar_profile', 'link_id' => $calendar->id,
        ])->assertRedirect();
        $this->assertSame('https://www.example.edu/original', $calendar->fresh()->source_url);
        $this->assertDatabaseCount('calendar_events', 0);

        $this->actingIn($owner, $tenant)->patch("/academic-setup/calendars/{$calendar->id}", $this->calendarPayload([
            'source_url' => 'https://www.example.edu/revised',
        ]))->assertRedirect();
        $this->assertDatabaseHas('academic_source_links', ['academic_source_id' => $source->id, 'link_type' => 'calendar_profile', 'link_id' => $calendar->id]);

        $this->actingIn($owner, $tenant)->patch("/academic-setup/calendars/{$calendar->id}", $this->calendarPayload([
            'source_url' => null,
        ]))->assertRedirect();
        $this->assertDatabaseHas('academic_source_links', ['academic_source_id' => $source->id, 'link_type' => 'calendar_profile', 'link_id' => $calendar->id]);
        $this->assertDatabaseCount('calendar_events', 0);
        $this->actingIn($owner, $tenant)->get("/academic-setup/calendars/{$calendar->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('summaries.0.base_days', 207)
                ->where('summaries.0.removed_days', 0)
                ->where('summaries.0.added_days', 0)
                ->where('summaries.0.scheduled_days', 207));
    }

    public function test_index_and_overview_report_provenance_without_changing_completion(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $calendar = $this->calendar($year, ['source_url' => 'https://www.example.edu/calendar']);
        $source = $this->source($year);
        $year->academicConfiguration()->create(['calendar_profile_id' => $calendar->id, 'status' => 'draft']);

        $this->actingIn($owner, $tenant)->get('/academic-setup/calendars')->assertInertia(fn (Assert $page) => $page
            ->where('calendars.0.has_source_website', true)
            ->where('calendars.0.linked_sources', [])
            ->missing('calendars.0.source_url'));
        $this->actingIn($owner, $tenant)->get('/academic-setup?school_year_id='.$year->id)->assertInertia(fn (Assert $page) => $page
            ->where('calendarSetup.state', 'complete')
            ->where('calendarSetup.selected_profile_has_source_website', true)
            ->where('calendarSetup.unlinked_source_count', 1)
            ->where('calendarSetup.single_source.id', $source->id)
            ->where('checklist.calendar', true));
    }

    public function test_student_and_cross_tenant_users_cannot_access_calendar_provenance(): void
    {
        [$ownerA, $tenantA] = $this->tenantUser('owner', 'Tenant A');
        [$ownerB, $tenantB] = $this->tenantUser('owner', 'Tenant B');
        $yearA = $this->schoolYear($ownerA, $tenantA);
        $calendar = $this->calendar($yearA, ['source_url' => 'https://www.example.edu/calendar']);
        $localSource = $this->source($yearA);

        $this->setContext($ownerB, $tenantB);
        $foreignSource = AcademicSource::create([
            'title' => 'Foreign calendar', 'source_kind' => 'manual', 'source_category' => 'calendar',
            'authority_level' => 'tenant_created', 'review_status' => 'reviewed', 'processing_status' => 'not_requested',
        ]);
        $this->actingIn($ownerA, $tenantA)->get("/academic-setup/calendars/{$calendar->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('suggestedSources', fn ($sources) => collect($sources)->pluck('id')->all() === [$localSource->id]
                    && ! collect($sources)->pluck('id')->contains($foreignSource->id)));

        $studentUser = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenantA->id, 'user_id' => $studentUser->id, 'role' => 'student', 'status' => 'active']);
        $this->setContext($ownerA, $tenantA);
        Student::create(['user_id' => $studentUser->id, 'first_name' => 'Student', 'last_name' => 'User', 'status' => 'active']);
        $this->actingIn($studentUser, $tenantA)->get("/academic-setup/calendars/{$calendar->id}")->assertForbidden();
        $this->actingIn($studentUser, $tenantA)->get("/academic-setup/sources/{$localSource->id}")->assertForbidden();
    }

    private function calendarPayload(array $overrides = []): array
    {
        return array_merge([
            'education_provider_id' => null, 'name' => 'District Calendar', 'academic_year_label' => '2026-2027',
            'start_date' => '2026-08-12', 'end_date' => '2027-05-27', 'timezone' => 'America/Chicago',
            'status' => 'draft', 'source_type' => 'manual', 'source_url' => null, 'source_version' => null, 'notes' => null,
        ], $overrides);
    }

    private function calendar(SchoolYear $year, array $overrides = []): CalendarProfile
    {
        return CalendarProfile::create($this->calendarPayload(array_merge([
            'name' => 'Calendar '.$year->name,
        ], $overrides)));
    }

    private function source(SchoolYear $year, array $overrides = []): AcademicSource
    {
        return AcademicSource::create(array_merge([
            'title' => 'Calendar PDF', 'source_kind' => 'upload', 'source_category' => 'calendar',
            'authority_level' => 'official_provider', 'review_status' => 'unreviewed', 'processing_status' => 'not_requested',
            'school_year_id' => $year->id, 'academic_year_label' => $year->name,
        ], $overrides));
    }

    private function tenantUser(string $role = 'owner', string $name = 'Calendar Academy'): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => $name, 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }

    private function schoolYear(User $user, Tenant $tenant): SchoolYear
    {
        $this->setContext($user, $tenant);

        return SchoolYear::create([
            'name' => '2026-2027', 'start_date' => '2026-08-12', 'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => 1,
            'instructional_week_type' => 'five_day', 'instructional_weekdays' => [1, 2, 3, 4, 5],
        ]);
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    }

    private function setContext(User $user, Tenant $tenant): void
    {
        $membership = TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);
    }
}
