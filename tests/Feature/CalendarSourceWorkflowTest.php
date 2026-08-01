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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarSourceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_overview_distinguishes_missing_source_profile_and_complete_states(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);

        $this->actingIn($owner, $tenant)->get('/academic-setup?school_year_id='.$year->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('calendarSetup.state', 'missing')
                ->where('calendarSetup.source_count', 0)
                ->where('checklist.calendar', false));

        $this->setContext($owner, $tenant);
        $source = $this->calendarSource($year, 'unreviewed');
        $this->actingIn($owner, $tenant)->get('/academic-setup?school_year_id='.$year->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('calendarSetup.state', 'source_available')
                ->where('calendarSetup.source_count', 1)
                ->where('calendarSetup.single_source.id', $source->id)
                ->where('calendarSetup.can_create_profile', false)
                ->where('checklist.calendar', false));

        $source->update(['review_status' => 'reviewed']);
        $this->actingIn($owner, $tenant)->get('/academic-setup?school_year_id='.$year->id)
            ->assertInertia(fn (Assert $page) => $page->where('calendarSetup.can_create_profile', true));

        $calendar = $this->calendar($year, ['status' => 'draft']);
        $source->links()->create(['link_type' => 'calendar_profile', 'link_id' => $calendar->id]);
        $this->actingIn($owner, $tenant)->get('/academic-setup?school_year_id='.$year->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('calendarSetup.state', 'draft_profile_available')
                ->where('calendarSetup.linked_profile_count', 1)
                ->where('checklist.calendar', false)
                ->where('choices.calendars.0.id', $calendar->id));

        $this->actingIn($owner, $tenant)->post('/academic-setup/configuration', [
            'school_year_id' => $year->id, 'calendar_profile_id' => $calendar->id, 'status' => 'draft',
        ])->assertRedirect();
        $this->actingIn($owner, $tenant)->get('/academic-setup?school_year_id='.$year->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('calendarSetup.state', 'complete')
                ->where('checklist.calendar', true));

        $this->actingIn($owner, $tenant)->post('/academic-setup/configuration', [
            'school_year_id' => $year->id, 'calendar_profile_id' => null, 'status' => 'draft',
        ])->assertRedirect();
        $this->actingIn($owner, $tenant)->get('/academic-setup?school_year_id='.$year->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('calendarSetup.state', 'draft_profile_available')
                ->where('checklist.calendar', false));
    }

    public function test_multiple_sources_use_filtered_library_and_never_choose_one_arbitrarily(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $this->setContext($owner, $tenant);
        $first = $this->calendarSource($year, 'reviewed', 'Calendar A');
        $second = $this->calendarSource($year, 'reviewed', 'Calendar B');
        AcademicSource::create([
            'title' => 'Unrelated curriculum', 'description' => 'Not a calendar', 'source_kind' => 'manual',
            'source_category' => 'curriculum', 'authority_level' => 'tenant_created',
            'review_status' => 'reviewed', 'processing_status' => 'not_requested', 'school_year_id' => $year->id,
        ]);

        $this->actingIn($owner, $tenant)->get('/academic-setup?school_year_id='.$year->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('calendarSetup.source_count', 2)
                ->where('calendarSetup.single_source', null)
                ->where('calendarSetup.can_create_profile', false));

        $this->actingIn($owner, $tenant)->get('/academic-setup/sources?category=calendar&school_year_id='.$year->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('filterSummary', 'Calendar sources for 2026-2027')
                ->has('sources.data', 2)
                ->where('sources.data', fn ($sources) => collect($sources)->pluck('id')->sort()->values()->all() === collect([$first->id, $second->id])->sort()->values()->all()));
    }

    public function test_authorized_pdf_preview_is_inline_private_and_tenant_scoped(): void
    {
        Storage::fake('local');
        [$ownerA, $tenantA] = $this->tenantUser('owner', 'Preview A');
        [$ownerB, $tenantB] = $this->tenantUser('owner', 'Preview B');
        $year = $this->schoolYear($ownerA, $tenantA);
        $this->actingIn($ownerA, $tenantA)->post('/academic-setup/sources', [
            ...$this->sourcePayload($year), 'source_file' => $this->pdf('district-calendar.pdf'),
        ])->assertRedirect();
        $this->setContext($ownerA, $tenantA);
        $source = AcademicSource::query()->firstOrFail();
        $file = $source->currentFile()->firstOrFail();

        $preview = $this->actingIn($ownerA, $tenantA)->get("/academic-setup/sources/{$source->id}/files/{$file->id}/view");
        $preview->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringStartsWith('inline;', (string) $preview->headers->get('content-disposition'));
        $this->assertStringNotContainsString('academic-sources/', implode(' ', $preview->headers->all('content-disposition')));
        $this->assertDatabaseHas('audit_logs', ['action' => 'academic-source.file-viewed']);

        $this->actingIn($ownerB, $tenantB)->get("/academic-setup/sources/{$source->id}/files/{$file->id}/view")->assertNotFound();

        $studentUser = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenantA->id, 'user_id' => $studentUser->id, 'role' => 'student', 'status' => 'active']);
        $this->setContext($ownerA, $tenantA);
        Student::create(['user_id' => $studentUser->id, 'first_name' => 'Student', 'last_name' => 'Viewer', 'status' => 'active']);
        $this->actingIn($studentUser, $tenantA)->get("/academic-setup/sources/{$source->id}/files/{$file->id}/view")->assertForbidden();
    }

    public function test_unsupported_file_is_never_served_inline(): void
    {
        Storage::fake('local');
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $this->actingIn($owner, $tenant)->post('/academic-setup/sources', [
            ...$this->sourcePayload($year),
            'source_file' => $this->file('notes.txt', 'text/plain', 'Calendar notes'),
        ])->assertRedirect();
        $source = AcademicSource::query()->firstOrFail();
        $file = $source->currentFile()->firstOrFail();

        $this->actingIn($owner, $tenant)->get("/academic-setup/sources/{$source->id}/files/{$file->id}/view")
            ->assertStatus(415);
        $this->actingIn($owner, $tenant)->get("/academic-setup/sources/{$source->id}/files/{$file->id}/download")
            ->assertOk();
    }

    public function test_reviewed_source_creates_empty_linked_draft_visible_in_calendar_workflow(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($owner, $tenant);
        $this->setContext($owner, $tenant);
        $source = $this->calendarSource($year, 'reviewed');

        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/draft-calendar")
            ->assertRedirect();
        $calendar = CalendarProfile::query()->firstOrFail();
        $this->assertSame('draft', $calendar->status);
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseHas('academic_source_links', ['academic_source_id' => $source->id, 'link_type' => 'calendar_profile', 'link_id' => $calendar->id]);

        $this->actingIn($owner, $tenant)->get('/academic-setup/calendars')->assertInertia(fn (Assert $page) => $page
            ->where('calendars.0.id', $calendar->id)
            ->where('calendars.0.status', 'draft')
            ->where('calendars.0.linked_sources.0.id', $source->id));
        $this->actingIn($owner, $tenant)->get('/academic-setup/calendars/'.$calendar->id)->assertInertia(fn (Assert $page) => $page
            ->where('linkedSources.0.id', $source->id)
            ->where('summaries.0.school_year_id', $year->id)
            ->where('summaries.0.compatible', true));
        $this->actingIn($owner, $tenant)->get('/academic-setup?school_year_id='.$year->id)->assertInertia(fn (Assert $page) => $page
            ->where('calendarSetup.state', 'draft_profile_available')
            ->where('choices.calendars.0.id', $calendar->id)
            ->where('summary.removed_days', 0)
            ->where('summary.scheduled_days', 207)
            ->where('checklist.calendar', false));
    }

    public function test_calendar_selection_rejects_incompatible_lifecycle_provider_and_tenant(): void
    {
        [$ownerA, $tenantA] = $this->tenantUser('owner', 'Config A');
        [$ownerB, $tenantB] = $this->tenantUser('owner', 'Config B');
        $yearA = $this->schoolYear($ownerA, $tenantA);
        $yearB = $this->schoolYear($ownerB, $tenantB);
        $this->setContext($ownerB, $tenantB);
        $foreign = $this->calendar($yearB);
        $this->setContext($ownerA, $tenantA);
        $retired = $this->calendar($yearA, ['name' => 'Retired', 'status' => 'retired']);
        $short = $this->calendar($yearA, ['name' => 'Too short', 'end_date' => '2027-05-01']);

        foreach ([$foreign->id, $retired->id, $short->id] as $calendarId) {
            $this->actingIn($ownerA, $tenantA)->post('/academic-setup/configuration', [
                'school_year_id' => $yearA->id, 'calendar_profile_id' => $calendarId, 'status' => 'draft',
            ])->assertSessionHasErrors('calendar_profile_id');
        }
        $this->assertDatabaseMissing('academic_year_configurations', ['school_year_id' => $yearA->id]);
    }

    private function sourcePayload(SchoolYear $year): array
    {
        return [
            'title' => 'Calendar PDF', 'description' => 'Calendar source for review.', 'source_kind' => 'upload',
            'source_category' => 'calendar', 'authority_level' => 'official_provider', 'school_year_id' => $year->id,
            'education_provider_id' => null, 'grade_level_id' => null, 'subject_id' => null,
            'version_label' => null, 'academic_year_label' => $year->name, 'publication_date' => null,
            'source_url' => null, 'notes' => null,
        ];
    }

    private function calendarSource(SchoolYear $year, string $reviewStatus, string $title = 'Calendar Source'): AcademicSource
    {
        return AcademicSource::create([
            'title' => $title, 'description' => 'Calendar source for adult review.', 'source_kind' => 'manual',
            'source_category' => 'calendar', 'authority_level' => 'tenant_created', 'school_year_id' => $year->id,
            'review_status' => $reviewStatus, 'processing_status' => 'not_requested', 'academic_year_label' => $year->name,
        ]);
    }

    private function calendar(SchoolYear $year, array $overrides = []): CalendarProfile
    {
        return CalendarProfile::create(array_merge([
            'name' => 'Calendar '.$year->name, 'academic_year_label' => $year->name,
            'start_date' => $year->start_date->format('Y-m-d'), 'end_date' => $year->end_date->format('Y-m-d'),
            'timezone' => 'America/Chicago', 'status' => 'draft', 'source_type' => 'manual',
        ], $overrides));
    }

    private function pdf(string $name): UploadedFile
    {
        return $this->file($name, 'application/pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }

    private function file(string $name, string $mime, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'calendar-source-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, $mime, null, true);
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
            'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => 180,
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
