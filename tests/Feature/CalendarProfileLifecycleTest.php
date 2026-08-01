<?php

namespace Tests\Feature;

use App\Models\AcademicSource;
use App\Models\AcademicYearConfiguration;
use App\Models\CalendarProfile;
use App\Models\EducationProvider;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarProfileLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_and_administrator_can_archive_unused_profiles_but_other_roles_cannot(): void
    {
        foreach (['owner', 'administrator'] as $role) {
            [$user, $tenant] = $this->tenantUser($role, ucfirst($role).' Academy');
            $year = $this->schoolYear($user, $tenant);
            $calendar = $this->calendar($year);
            $this->actingIn($user, $tenant)->patch("/academic-setup/calendars/{$calendar->id}/archive")->assertRedirect('/academic-setup/calendars');
            $this->assertSame('archived', $calendar->fresh()->status);
            $this->assertDatabaseHas('audit_logs', ['action' => 'calendar-profile.archived', 'auditable_id' => (string) $calendar->id]);
        }

        foreach (['teacher', 'parent', 'tutor', 'student'] as $role) {
            [$user, $tenant] = $this->tenantUser($role, ucfirst($role).' Academy');
            $year = $this->schoolYear($user, $tenant);
            $calendar = $this->calendar($year);
            if ($role === 'student') {
                Student::create(['user_id' => $user->id, 'first_name' => 'Student', 'last_name' => 'User', 'status' => 'active']);
            }
            $this->actingIn($user, $tenant)->patch("/academic-setup/calendars/{$calendar->id}/archive")->assertForbidden();
            $this->assertSame('draft', $calendar->fresh()->status);
        }
    }

    public function test_index_filters_archived_profiles_and_returns_usage_and_capabilities(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $active = $this->calendar($year, ['name' => 'Current Calendar']);
        $archived = $this->calendar($year, ['name' => 'Archived Calendar', 'status' => 'archived']);
        AcademicYearConfiguration::create(['school_year_id' => $year->id, 'calendar_profile_id' => $active->id, 'status' => 'draft']);

        $this->actingIn($owner, $tenant)->get('/academic-setup/calendars')->assertInertia(fn (Assert $page) => $page
            ->where('filters.show', 'active')
            ->has('calendars', 1)
            ->where('calendars.0.id', $active->id)
            ->where('calendars.0.lifecycle.is_in_use', true)
            ->where('calendars.0.lifecycle.can_archive', false)
            ->where('calendars.0.lifecycle.can_delete', false));
        $this->actingIn($owner, $tenant)->get('/academic-setup/calendars?show=archived')->assertInertia(fn (Assert $page) => $page
            ->where('filters.show', 'archived')->has('calendars', 1)->where('calendars.0.id', $archived->id));
        $this->actingIn($owner, $tenant)->get('/academic-setup/calendars?show=all')->assertInertia(fn (Assert $page) => $page
            ->where('filters.show', 'all')->has('calendars', 2));
    }

    public function test_archive_preserves_events_links_url_audits_and_historical_references(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $calendar = $this->calendar($year, ['source_url' => 'https://www.example.edu/calendar']);
        $event = $calendar->events()->create($this->eventPayload());
        $source = $this->source($year);
        $link = $source->links()->create(['link_type' => 'calendar_profile', 'link_id' => $calendar->id]);
        $configuration = AcademicYearConfiguration::create(['school_year_id' => $year->id, 'calendar_profile_id' => $calendar->id, 'status' => 'closed']);
        $auditCount = DB::table('audit_logs')->count();

        $this->actingIn($owner, $tenant)->patch("/academic-setup/calendars/{$calendar->id}/archive")->assertRedirect();

        $this->assertDatabaseHas('calendar_profiles', ['id' => $calendar->id, 'status' => 'archived', 'source_url' => 'https://www.example.edu/calendar']);
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'calendar_profile_id' => $calendar->id]);
        $this->assertDatabaseHas('academic_source_links', ['id' => $link->id, 'academic_source_id' => $source->id]);
        $this->assertDatabaseHas('academic_year_configurations', ['id' => $configuration->id, 'calendar_profile_id' => $calendar->id]);
        $this->assertSame($auditCount + 1, DB::table('audit_logs')->count());
    }

    public function test_current_academic_setup_selection_blocks_archive_with_a_useful_summary(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $calendar = $this->calendar($year);
        AcademicYearConfiguration::create(['school_year_id' => $year->id, 'calendar_profile_id' => $calendar->id, 'status' => 'draft']);

        $this->actingIn($owner, $tenant)->get("/academic-setup/calendars/{$calendar->id}")->assertInertia(fn (Assert $page) => $page
            ->where('lifecycle.academic_configuration_count', 1)
            ->where('lifecycle.active_configuration_count', 1)
            ->where('lifecycle.can_archive', false)
            ->where('lifecycle.can_delete', false)
            ->where('lifecycle.usage.0.school_year', '2026-2027')
            ->where('lifecycle.archive_blockers.0', fn ($message) => str_contains($message, 'Choose another Calendar Profile')));
        $this->actingIn($owner, $tenant)->patch("/academic-setup/calendars/{$calendar->id}/archive")
            ->assertSessionHasErrors('lifecycle');
        $this->assertSame('draft', $calendar->fresh()->status);
    }

    public function test_restore_returns_to_draft_without_selecting_and_preserves_dependencies(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $calendar = $this->calendar($year, ['status' => 'archived']);
        $event = $calendar->events()->create($this->eventPayload());
        $source = $this->source($year);
        $link = $source->links()->create(['link_type' => 'calendar_profile', 'link_id' => $calendar->id]);

        $this->actingIn($owner, $tenant)->get("/academic-setup/calendars/{$calendar->id}/edit")->assertStatus(409);
        $this->actingIn($owner, $tenant)->patch("/academic-setup/calendars/{$calendar->id}", $this->calendarPayload())
            ->assertSessionHasErrors('status');
        $this->assertSame('archived', $calendar->fresh()->status);
        $this->actingIn($owner, $tenant)->patch("/academic-setup/calendars/{$calendar->id}/restore")
            ->assertRedirect()->assertSessionHas('success', 'Calendar Profile restored to Draft.');

        $this->assertSame('draft', $calendar->fresh()->status);
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id]);
        $this->assertDatabaseHas('academic_source_links', ['id' => $link->id]);
        $this->assertDatabaseMissing('academic_year_configurations', ['calendar_profile_id' => $calendar->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'calendar-profile.restored', 'auditable_id' => (string) $calendar->id]);
        $this->actingIn($owner, $tenant)->get("/academic-setup/calendars/{$calendar->id}/edit")->assertOk();
    }

    public function test_completely_unused_profile_can_be_deleted_without_related_record_damage_or_sensitive_audit_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $provider = EducationProvider::query()->firstOrFail();
        $calendar = $this->calendar($year, ['education_provider_id' => $provider->id, 'source_url' => 'https://www.example.edu/private-reference']);
        $source = $this->source($year);

        $this->actingIn($owner, $tenant)->delete("/academic-setup/calendars/{$calendar->id}", ['confirmation' => 'DELETE'])->assertRedirect('/academic-setup/calendars');

        $this->assertDatabaseMissing('calendar_profiles', ['id' => $calendar->id]);
        $this->assertDatabaseHas('education_providers', ['id' => $provider->id]);
        $this->assertDatabaseHas('school_years', ['id' => $year->id]);
        $this->assertDatabaseHas('academic_sources', ['id' => $source->id]);
        $audit = DB::table('audit_logs')->where('action', 'calendar-profile.deleted')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('source_url', (string) $audit->old_values);
        $this->assertStringNotContainsString('private-reference', (string) $audit->old_values);
    }

    public function test_delete_blockers_cover_events_sources_current_and_historical_configurations_and_are_rechecked(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $calendar = $this->calendar($year);

        $this->actingIn($owner, $tenant)->get("/academic-setup/calendars/{$calendar->id}")->assertInertia(fn (Assert $page) => $page->where('lifecycle.can_delete', true));
        $event = $calendar->events()->create($this->eventPayload());
        $source = $this->source($year);
        $source->links()->create(['link_type' => 'calendar_profile', 'link_id' => $calendar->id]);
        AcademicYearConfiguration::create(['school_year_id' => $year->id, 'calendar_profile_id' => $calendar->id, 'status' => 'archived']);

        $this->actingIn($owner, $tenant)->delete("/academic-setup/calendars/{$calendar->id}", ['confirmation' => 'DELETE'])
            ->assertSessionHasErrors('lifecycle');
        $this->assertDatabaseHas('calendar_profiles', ['id' => $calendar->id]);
        $this->actingIn($owner, $tenant)->get("/academic-setup/calendars/{$calendar->id}")->assertInertia(fn (Assert $page) => $page
            ->where('lifecycle.event_count', 1)
            ->where('lifecycle.linked_source_count', 1)
            ->where('lifecycle.academic_configuration_count', 1)
            ->where('lifecycle.can_delete', false)
            ->where('lifecycle.deletion_blockers', fn ($blockers) => collect($blockers)->contains(fn ($message) => str_contains($message, 'Calendar Event'))
                && collect($blockers)->contains(fn ($message) => str_contains($message, 'linked source'))
                && collect($blockers)->contains(fn ($message) => str_contains($message, 'historical'))));
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id]);
    }

    public function test_cross_tenant_and_shared_lifecycle_actions_fail_closed(): void
    {
        [$ownerA, $tenantA] = $this->tenantUser('owner', 'Tenant A');
        [$ownerB, $tenantB] = $this->tenantUser('owner', 'Tenant B');
        $yearB = $this->schoolYear($ownerB, $tenantB);
        $foreign = $this->calendar($yearB);

        foreach (['archive' => 'patch', 'restore' => 'patch'] as $action => $method) {
            $this->actingIn($ownerA, $tenantA)->{$method}("/academic-setup/calendars/{$foreign->id}/{$action}")->assertNotFound();
        }
        $this->actingIn($ownerA, $tenantA)->delete("/academic-setup/calendars/{$foreign->id}", ['confirmation' => 'DELETE'])->assertNotFound();

        $sharedId = DB::table('calendar_profiles')->insertGetId([
            'tenant_id' => null, 'ownership_key' => 'platform', 'education_provider_id' => null,
            'name' => 'Shared Calendar', 'academic_year_label' => '2026-2027', 'start_date' => '2026-08-12',
            'end_date' => '2027-05-27', 'timezone' => 'America/Chicago', 'status' => 'draft', 'source_type' => 'provider',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingIn($ownerA, $tenantA)->patch("/academic-setup/calendars/{$sharedId}/archive")->assertForbidden();
        $this->actingIn($ownerA, $tenantA)->delete("/academic-setup/calendars/{$sharedId}", ['confirmation' => 'DELETE'])->assertForbidden();
        $this->assertDatabaseHas('calendar_profiles', ['id' => $sharedId, 'tenant_id' => null]);
    }

    private function calendarPayload(array $overrides = []): array
    {
        return array_merge([
            'education_provider_id' => null, 'name' => 'Lifecycle Calendar', 'academic_year_label' => '2026-2027',
            'start_date' => '2026-08-12', 'end_date' => '2027-05-27', 'timezone' => 'America/Chicago',
            'status' => 'draft', 'source_type' => 'manual', 'source_url' => null, 'source_version' => null, 'notes' => null,
        ], $overrides);
    }

    private function calendar(SchoolYear $year, array $overrides = []): CalendarProfile
    {
        return CalendarProfile::create($this->calendarPayload(array_merge(['name' => 'Calendar '.$year->name.' '.uniqid()], $overrides)));
    }

    private function eventPayload(): array
    {
        return ['event_date' => '2026-09-07', 'end_date' => null, 'event_type' => 'holiday', 'name' => 'Holiday', 'instructional_effect' => 'non_instructional', 'status' => 'active'];
    }

    private function source(SchoolYear $year): AcademicSource
    {
        return AcademicSource::create([
            'title' => 'Calendar Source '.uniqid(), 'source_kind' => 'manual', 'source_category' => 'calendar',
            'authority_level' => 'tenant_created', 'review_status' => 'unreviewed', 'processing_status' => 'not_requested',
            'school_year_id' => $year->id,
        ]);
    }

    private function tenantUser(string $role = 'owner', string $name = 'Lifecycle Academy'): array
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
