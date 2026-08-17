<?php

namespace Tests\Feature;

use App\Models\CalendarProfile;
use App\Models\CreativeWritingEntry;
use App\Models\CreativeWritingPrompt;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CreativeWritingJournalService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\CreativeWritingPromptSeeder;
use Database\Seeders\GradeLevelSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreativeWritingJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GradeLevelSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-17 08:00:00', 'America/Chicago'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_one_persistent_assignment_is_reused_on_reload_and_appears_on_dashboard(): void
    {
        $world = $this->world();
        $this->prompt('First Mission');

        $dashboard = $this->actingAs($world['student_user'])->get(route('student.home'))->assertOk();
        $first = CreativeWritingEntry::firstOrFail();
        $dashboard->assertInertia(fn (Assert $page) => $page
                ->component('StudentPortal/Home')
                ->where('writingJournal.title', 'First Mission')
                ->where('writingJournal.status', 'assigned')
                ->where('writingJournal.url', route('student.writing-journal.show', $first)));

        $this->app->forgetInstance(TenantContext::class);
        $this->actingAs($world['student_user'])
            ->get(route('student.writing-journal.show', $first))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('StudentJournal/Show')
                ->where('entry.id', $first->id)
                ->where('entry.status', 'assigned'));
        $this->actingAs($world['student_user'])->get(route('student.home'))->assertOk();

        $this->assertDatabaseCount('creative_writing_entries', 1);
        $this->assertSame($first->id, CreativeWritingEntry::firstOrFail()->id);
        $this->expectException(QueryException::class);
        CreativeWritingEntry::create($this->entryAttributes($world['enrollment'], CreativeWritingPrompt::firstOrFail()));
    }

    public function test_prompt_rotation_is_independent_per_student_and_does_not_repeat_until_exhausted(): void
    {
        $world = $this->world();
        $second = $this->addStudent($world, 'Mina', 'mina.writer');
        foreach (['Alpha', 'Beta', 'Gamma'] as $title) {
            $this->prompt($title);
        }

        $service = app(CreativeWritingJournalService::class);
        $kaiPromptIds = collect(['2026-08-17', '2026-08-18', '2026-08-19'])
            ->map(fn (string $date) => $service->assignmentForDate($world['enrollment'], $date)->creative_writing_prompt_id);

        $this->assertCount(3, $kaiPromptIds->unique());
        $minaEntry = $service->assignmentForDate($second['enrollment'], '2026-08-17');
        $this->assertNotNull($minaEntry);
        $this->assertSame($second['student']->id, $minaEntry->student_id);
        $this->assertDatabaseCount('creative_writing_entries', 4);
    }

    public function test_inactive_prompts_and_noninstructional_dates_are_not_assigned(): void
    {
        $world = $this->world();
        $inactive = $this->prompt('Retired Mission', false);
        $active = $this->prompt('Available Mission');
        $service = app(CreativeWritingJournalService::class);

        $entry = $service->assignmentForDate($world['enrollment'], '2026-08-17');
        $this->assertSame($active->id, $entry->creative_writing_prompt_id);
        $this->assertNotSame($inactive->id, $entry->creative_writing_prompt_id);
        $this->assertNull($service->assignmentForDate($world['enrollment'], '2026-08-22'));
        $this->assertDatabaseMissing('creative_writing_entries', ['instructional_date' => '2026-08-22']);
    }

    public function test_starter_library_seeds_exactly_twenty_prompts_without_reactivating_teacher_changes(): void
    {
        $this->world();
        $this->seed(CreativeWritingPromptSeeder::class);

        $this->assertDatabaseCount('creative_writing_prompts', 20);
        $prompt = CreativeWritingPrompt::where('source_key', 'starter-1')->firstOrFail();
        $prompt->update(['active' => false]);
        $this->seed(CreativeWritingPromptSeeder::class);

        $this->assertDatabaseCount('creative_writing_prompts', 20);
        $this->assertFalse($prompt->fresh()->active);
        $this->assertSame('Gravity Stops Working', $prompt->title);
        $this->assertCount(5, $prompt->include_hints);
    }

    public function test_student_can_autosave_and_submit_own_entry_but_cannot_change_another_students_entry(): void
    {
        $world = $this->world();
        $other = $this->addStudent($world, 'Mina', 'mina.writer');
        $this->prompt('Draft Mission');
        $service = app(CreativeWritingJournalService::class);
        $entry = $service->assignmentForDate($world['enrollment'], '2026-08-17');
        $otherEntry = $service->assignmentForDate($other['enrollment'], '2026-08-17');

        $this->app->forgetInstance(TenantContext::class);
        $this->actingAs($world['student_user'])->get(route('student.writing-journal.show', $otherEntry))->assertNotFound();
        $this->app->forgetInstance(TenantContext::class);
        $this->actingAs($world['student_user'])->patchJson(route('student.writing-journal.draft', $entry), [
            'response' => "A bright ship arrived.\n\nKai opened the door.",
        ])->assertOk()->assertJsonPath('status', 'in_progress')->assertJsonPath('word_count', 8);
        $this->actingAs($world['student_user'])->patchJson(route('student.writing-journal.draft', $otherEntry), [
            'response' => 'Not mine',
        ])->assertNotFound();

        $this->actingAs($world['student_user'])->post(route('student.writing-journal.submit', $entry), [
            'response' => 'A complete short story with a clear ending.',
        ])->assertRedirect();
        $entry->refresh();
        $this->assertSame('submitted', $entry->status);
        $this->assertNotNull($entry->submitted_at);
        $this->assertSame('A complete short story with a clear ending.', $entry->response);

        $this->actingAs($world['student_user'])->patchJson(route('student.writing-journal.draft', $entry), [
            'response' => 'Changed after submission',
        ])->assertUnprocessable();
        $this->assertSame('A complete short story with a clear ending.', $entry->fresh()->response);
    }

    public function test_prompt_edits_preserve_entry_snapshot_and_teacher_views_are_tenant_isolated(): void
    {
        $world = $this->world();
        $prompt = $this->prompt('Original Title');
        $entry = app(CreativeWritingJournalService::class)->assignmentForDate($world['enrollment'], '2026-08-17');
        $prompt->update(['title' => 'Revised Title', 'prompt' => 'A different prompt for future assignments.']);

        $this->assertSame('Original Title', $entry->fresh()->prompt_title_snapshot);
        $this->actingAs($world['owner'])->withSession(['active_tenant_id' => $world['tenant']->id])
            ->get(route('creative-writing.show', $entry))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CreativeWriting/Show')
                ->where('entry.title', 'Original Title')
                ->where('entry.student', 'Kai Singh'));

        $foreign = $this->world('Other Academy', 'nova.writer');
        app(TenantContext::class)->set($foreign['tenant'], $foreign['owner_membership']);
        $this->actingAs($foreign['owner'])->withSession(['active_tenant_id' => $foreign['tenant']->id])
            ->get('/creative-writing/entries/'.$entry->id)
            ->assertNotFound();
    }

    private function world(string $tenantName = 'Cosmic Quest Academy', string $username = 'kai.writer'): array
    {
        $tenant = Tenant::create(['name' => $tenantName, 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        $owner = User::factory()->create();
        $ownerMembership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        app(TenantContext::class)->set($tenant, $ownerMembership);
        $year = $tenant->schoolYears()->create(['name' => '2026-2027', 'start_date' => '2026-08-12', 'end_date' => '2027-05-27', 'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_weekdays' => [1, 2, 3, 4, 5]]);
        $calendar = CalendarProfile::create(['name' => 'Instructional Calendar', 'academic_year_label' => '2026-2027', 'start_date' => '2026-08-12', 'end_date' => '2027-05-27', 'timezone' => 'America/Chicago', 'status' => 'active', 'source_type' => 'manual']);
        $year->academicConfiguration()->create(['calendar_profile_id' => $calendar->id, 'status' => 'draft']);
        $studentUser = User::factory()->create(['email' => null, 'username' => $username, 'must_change_password' => false]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $studentUser->id, 'role' => 'student', 'status' => 'active']);
        $student = $tenant->students()->create(['user_id' => $studentUser->id, 'student_access_enabled_at' => now(), 'first_name' => 'Kai', 'last_name' => 'Singh', 'status' => 'active']);
        $enrollment = $tenant->enrollments()->create(['student_id' => $student->id, 'school_year_id' => $year->id, 'grade_level_id' => GradeLevel::where('code', 'G5')->value('id'), 'enrollment_date' => '2026-08-12', 'status' => 'active']);

        return compact('tenant', 'owner', 'ownerMembership', 'studentUser', 'student', 'enrollment') + ['owner_membership' => $ownerMembership, 'student_user' => $studentUser];
    }

    private function addStudent(array $world, string $name, string $username): array
    {
        $user = User::factory()->create(['email' => null, 'username' => $username, 'must_change_password' => false]);
        TenantMembership::create(['tenant_id' => $world['tenant']->id, 'user_id' => $user->id, 'role' => 'student', 'status' => 'active']);
        $student = $world['tenant']->students()->create(['user_id' => $user->id, 'student_access_enabled_at' => now(), 'first_name' => $name, 'last_name' => 'Singh', 'status' => 'active']);
        $enrollment = $world['tenant']->enrollments()->create(['student_id' => $student->id, 'school_year_id' => $world['enrollment']->school_year_id, 'grade_level_id' => $world['enrollment']->grade_level_id, 'enrollment_date' => '2026-08-12', 'status' => 'active']);

        return compact('user', 'student', 'enrollment');
    }

    private function prompt(string $title, bool $active = true): CreativeWritingPrompt
    {
        return CreativeWritingPrompt::create(['title' => $title, 'prompt' => 'Write a short imaginative story.', 'include_hints' => ['A character', 'A setting', 'An ending'], 'category' => 'Adventure', 'active' => $active, 'source_type' => 'teacher_created']);
    }

    private function entryAttributes(StudentEnrollment $enrollment, CreativeWritingPrompt $prompt): array
    {
        return ['student_id' => $enrollment->student_id, 'student_enrollment_id' => $enrollment->id, 'school_year_id' => $enrollment->school_year_id, 'creative_writing_prompt_id' => $prompt->id, 'instructional_date' => '2026-08-17', 'prompt_title_snapshot' => $prompt->title, 'prompt_snapshot' => $prompt->prompt, 'include_hints_snapshot' => $prompt->include_hints, 'category_snapshot' => $prompt->category, 'status' => 'assigned', 'word_count' => 0, 'assigned_at' => now()];
    }
}
