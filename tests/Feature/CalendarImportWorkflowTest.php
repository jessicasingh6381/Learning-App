<?php

namespace Tests\Feature;

use App\Contracts\PdfTextExtractor;
use App\Models\AcademicSource;
use App\Models\AuditLog;
use App\Models\CalendarImport;
use App\Models\CalendarProfile;
use App\Models\SchoolYear;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CalendarImportService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class CalendarImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private object $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->extractor = new class implements PdfTextExtractor {
            public array $pages = [];
            public function extract(string $absolutePath): array { return $this->pages; }
        };
        $this->app->instance(PdfTextExtractor::class, $this->extractor);
    }

    public function test_pdf_extraction_creates_reviewable_proposals_but_no_live_events(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $this->extractor->pages = $this->districtText();

        $response = $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $import = CalendarImport::query()->firstOrFail();
        $response->assertRedirect(route('academic.calendar-imports.show', $import));
        $this->assertSame('review_required', $import->status);
        $this->assertSame('general-text-v2', $import->parser_version);
        $this->assertSame('completed', $source->fresh()->processing_status);
        $this->assertSame('2026-08-12', $import->proposed_first_day->format('Y-m-d'));
        $this->assertSame('2027-05-27', $import->proposed_last_day->format('Y-m-d'));
        $this->assertDatabaseHas('calendar_import_proposals', ['event_type' => 'break', 'event_date' => '2026-11-23', 'end_date' => '2026-11-27']);
        $this->assertDatabaseHas('calendar_import_proposals', ['event_type' => 'teacher_workday', 'instructional_effect' => 'non_instructional']);
        $this->assertDatabaseHas('calendar_import_proposals', ['event_type' => 'student_holiday']);
        $this->assertDatabaseHas('calendar_import_proposals', ['event_type' => 'early_release', 'instructional_effect' => 'instructional']);
        $this->assertDatabaseCount('calendar_events', 0);

        $this->actingIn($owner, $tenant)->get("/academic-setup/calendar-imports/{$import->id}")->assertInertia(fn (Assert $page) => $page
            ->component('Academic/CalendarImports/Show')->has('proposals', 8)
            ->where('schoolYear.start_date', '2026-08-01')
            ->where('calendarImport.proposed_first_day', '2026-08-12')
            ->where('proposals.7.warnings.0', 'Outside the saved school-year dates'));
    }

    public function test_parser_supports_legend_abbreviations_numeric_dates_and_cross_month_ranges(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $this->extractor->pages = [['page' => 2, 'text' => implode("\n", [
            'TW = Teacher Workday',
            '11/3/2026 - TW',
            'Winter Break December 21 - January 1, 2027',
            '2/12/27 Early Release',
        ])]];

        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports")->assertRedirect();
        $import = CalendarImport::query()->firstOrFail();

        $this->assertDatabaseHas('calendar_import_proposals', [
            'calendar_import_id' => $import->id, 'event_date' => '2026-11-03',
            'event_type' => 'teacher_workday', 'source_page' => 2,
        ]);
        $this->assertDatabaseHas('calendar_import_proposals', [
            'calendar_import_id' => $import->id, 'event_date' => '2026-12-21',
            'end_date' => '2027-01-01', 'event_type' => 'break',
        ]);
        $this->assertDatabaseHas('calendar_import_proposals', [
            'calendar_import_id' => $import->id, 'event_date' => '2027-02-12',
            'event_type' => 'early_release', 'instructional_effect' => 'instructional',
        ]);
        $this->assertSame(3, $import->proposals()->count(), 'The legend definition itself must not become a proposal.');
        $this->assertDatabaseCount('calendar_events', 0);
    }

    public function test_cy_fair_positioned_parser_detects_format_and_maps_important_dates_without_legend_rows(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $source->update(['title' => 'Cy-Fair ISD School Calendar']);
        $this->extractor->pages = require base_path('tests/Fixtures/cy-fair-calendar-positioned.php');

        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports")->assertRedirect();
        $import = CalendarImport::query()->firstOrFail();

        $this->assertSame('review_required', $import->status);
        $this->assertSame('cy-fair-important-dates-v1', $import->parser_version);
        $this->assertSame('pdf_positioned_text', $import->extraction_method);
        $this->assertSame('2026-08-12', $import->proposed_first_day->format('Y-m-d'));
        $this->assertSame('2027-05-27', $import->proposed_last_day->format('Y-m-d'));
        $this->assertSame(18, $import->proposals()->count());
        $this->assertFalse($import->proposals()->whereNull('event_date')->exists());
        $this->assertDatabaseHas('calendar_import_proposals', [
            'calendar_import_id' => $import->id, 'event_date' => '2026-08-03',
            'end_date' => '2026-08-11', 'event_type' => 'professional_development',
        ]);
        $this->assertDatabaseHas('calendar_import_proposals', [
            'calendar_import_id' => $import->id, 'event_date' => '2026-12-21',
            'end_date' => '2027-01-01', 'name' => 'Student/Staff Holiday',
        ]);
        $this->assertDatabaseHas('calendar_import_proposals', [
            'calendar_import_id' => $import->id, 'event_date' => '2026-10-09',
            'event_type' => 'teacher_workday', 'instructional_effect' => 'non_instructional',
        ]);
        $this->assertDatabaseHas('calendar_import_proposals', [
            'calendar_import_id' => $import->id, 'event_date' => '2027-05-27',
            'event_type' => 'last_day', 'instructional_effect' => 'instructional',
        ]);
        $this->assertDatabaseMissing('calendar_import_proposals', [
            'calendar_import_id' => $import->id, 'name' => 'First and Last Days of School',
        ]);
    }

    public function test_cy_fair_quality_gate_requires_boundary_mapping_and_keeps_manual_correction_available(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $source->update(['title' => 'CyFair ISD District Calendar']);
        $this->extractor->pages = [[
            'page' => 1,
            'text' => 'IMPORTANT DATES GRADING PERIODS LEGEND DISTRICT CALENDAR First and Last Days of School Teacher Work Day/School Closure Make-up Day',
            'items' => [
                ['text' => 'IMPORTANT DATES', 'x' => 466.0, 'y' => 660.0, 'width' => 8.0, 'height' => 8.0],
                ['text' => 'Sept. 7', 'x' => 466.0, 'y' => 640.0, 'width' => 8.0, 'height' => 8.0],
                ['text' => 'Student/Staff Holiday', 'x' => 466.0, 'y' => 628.0, 'width' => 8.0, 'height' => 8.0],
                ['text' => 'Elementary', 'x' => 190.0, 'y' => 100.0, 'width' => 8.0, 'height' => 8.0],
            ],
        ]];

        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports")->assertRedirect();
        $import = CalendarImport::query()->firstOrFail();
        $this->assertSame('manual_handling', $import->status);
        $this->assertStringContainsString('first or last day', $import->diagnostic);
        $this->assertDatabaseCount('calendar_import_proposals', 1);

        $this->actingIn($owner, $tenant)->get("/academic-setup/calendar-imports/{$import->id}")->assertInertia(fn (Assert $page) => $page
            ->where('calendarImport.status', 'manual_handling')
            ->where('canManage', true)
            ->where('canRetry', true));

        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$import->id}/proposals", [
            'event_date' => null, 'end_date' => null, 'name' => 'Date still needs review',
            'event_type' => 'other', 'instructional_effect' => 'informational', 'included' => true,
        ])->assertRedirect();
        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$import->id}/approve", [
            'replace_previous' => false, 'update_school_year_dates' => false,
        ])->assertSessionHasErrors('approval');
        $this->assertDatabaseCount('calendar_events', 0);
    }

    public function test_legacy_undated_import_is_presented_as_manual_handling_without_junk_rows_and_can_retry(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $import = CalendarImport::create([
            'academic_source_id' => $source->id,
            'academic_source_file_id' => $source->currentFile->id,
            'school_year_id' => $year->id,
            'created_by_user_id' => $owner->id,
            'status' => 'review_required',
            'extraction_method' => 'pdf_text',
            'parser_version' => 'general-text-v2',
        ]);
        foreach (['First and Last Days of School', 'Student/Staff Holiday'] as $name) {
            $import->proposals()->create([
                'event_date' => null, 'end_date' => null, 'name' => $name,
                'event_type' => 'other', 'instructional_effect' => 'informational',
                'confidence' => 0.35, 'source_page' => 1, 'raw_text' => $name,
                'included' => false,
            ]);
        }

        $this->actingIn($owner, $tenant)->get("/academic-setup/calendar-imports/{$import->id}")->assertInertia(fn (Assert $page) => $page
            ->where('calendarImport.status', 'manual_handling')
            ->where('calendarImport.diagnostic', fn ($value) => str_contains($value, '0 of 2'))
            ->has('proposals', 0)
            ->where('canManage', true)
            ->where('canRetry', true));
    }

    public function test_bulk_review_update_saves_multiple_fields_checkboxes_and_is_idempotent(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $this->extractor->pages = [['page' => 1, 'text' => "September 7, 2026 - Holiday\nOctober 9, 2026 - Teacher Workday"]];
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $import = CalendarImport::query()->firstOrFail();
        $proposals = $import->proposals()->get();
        $first = $proposals->first();
        $second = $proposals->last();
        $evidence = $first->only(['source_page', 'raw_text', 'parser_note', 'confidence']);
        $payload = ['proposals' => [
            $first->id => [
                'id' => $first->id, 'included' => false, 'event_date' => '2026-09-08', 'end_date' => '2026-09-09',
                'name' => 'Renamed district holiday', 'event_type' => 'break', 'instructional_effect' => 'informational',
            ],
            $second->id => [
                'id' => $second->id, 'included' => true, 'event_date' => '2026-10-10', 'end_date' => null,
                'name' => 'Revised staff workday', 'event_type' => 'staff_development', 'instructional_effect' => 'non_instructional',
            ],
        ]];

        $this->actingIn($owner, $tenant)->put("/academic-setup/calendar-imports/{$import->id}/proposals", $payload)
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Review changes saved.');
        $this->assertDatabaseHas('calendar_import_proposals', [
            'id' => $first->id, 'included' => false, 'event_date' => '2026-09-08', 'end_date' => '2026-09-09',
            'name' => 'Renamed district holiday', 'event_type' => 'break', 'instructional_effect' => 'informational', 'manually_edited' => true,
        ]);
        $this->assertDatabaseHas('calendar_import_proposals', [
            'id' => $second->id, 'included' => true, 'event_date' => '2026-10-10',
            'name' => 'Revised staff workday', 'event_type' => 'staff_development', 'instructional_effect' => 'non_instructional', 'manually_edited' => true,
        ]);
        $this->assertSame($evidence, $first->fresh()->only(['source_page', 'raw_text', 'parser_note', 'confidence']));

        $auditCount = AuditLog::query()->where('action', 'calendar-import.proposal-updated')->count();
        $this->actingIn($owner, $tenant)->put("/academic-setup/calendar-imports/{$import->id}/proposals", $payload)
            ->assertSessionHasNoErrors();
        $this->assertSame($auditCount, AuditLog::query()->where('action', 'calendar-import.proposal-updated')->count());
    }

    public function test_bulk_review_validation_is_atomic_row_specific_and_rejects_foreign_proposals(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $this->extractor->pages = [['page' => 1, 'text' => "September 7, 2026 - Holiday\nOctober 9, 2026 - Teacher Workday"]];
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $import = CalendarImport::query()->firstOrFail();
        [$first, $second] = $import->proposals()->get()->values()->all();

        $this->actingIn($owner, $tenant)->put("/academic-setup/calendar-imports/{$import->id}/proposals", ['proposals' => [
            $first->id => ['id' => $first->id, 'included' => false, 'event_date' => '2026-09-08', 'end_date' => null, 'name' => 'Must roll back', 'event_type' => 'break', 'instructional_effect' => 'informational'],
            $second->id => ['id' => $second->id, 'included' => true, 'event_date' => '2026-10-10', 'end_date' => '2026-10-09', 'name' => '', 'event_type' => 'teacher_workday', 'instructional_effect' => 'non_instructional'],
        ]])->assertSessionHasErrors(["proposals.{$second->id}.name", "proposals.{$second->id}.end_date"]);
        $this->assertSame($first->name, $first->fresh()->name);
        $this->assertTrue($first->fresh()->included);

        $otherSource = $this->calendarSource($owner, $tenant, $year);
        $otherImport = CalendarImport::create([
            'academic_source_id' => $otherSource->id, 'academic_source_file_id' => $otherSource->currentFile->id,
            'school_year_id' => $year->id, 'created_by_user_id' => $owner->id, 'status' => 'review_required',
            'extraction_method' => 'pdf_text', 'parser_version' => 'test',
        ]);
        $foreign = $otherImport->proposals()->create([
            'event_date' => '2026-11-01', 'name' => 'Foreign proposal', 'event_type' => 'other',
            'instructional_effect' => 'informational', 'included' => true,
        ]);
        $this->actingIn($owner, $tenant)->put("/academic-setup/calendar-imports/{$import->id}/proposals", ['proposals' => [
            $foreign->id => ['id' => $foreign->id, 'included' => true, 'event_date' => '2026-11-02', 'end_date' => null, 'name' => 'Moved foreign proposal', 'event_type' => 'other', 'instructional_effect' => 'informational'],
        ]])->assertSessionHasErrors(['review', "proposals.{$foreign->id}.id"]);
        $this->assertSame('2026-11-01', $foreign->fresh()->event_date->format('Y-m-d'));

        [$otherOwner, $otherTenant] = $this->tenantUser('owner', 'Other tenant');
        $otherYear = $this->schoolYear($otherTenant);
        $crossTenantSource = $this->calendarSource($otherOwner, $otherTenant, $otherYear);
        $crossTenantImport = CalendarImport::create([
            'academic_source_id' => $crossTenantSource->id, 'academic_source_file_id' => $crossTenantSource->currentFile->id,
            'school_year_id' => $otherYear->id, 'created_by_user_id' => $otherOwner->id, 'status' => 'review_required',
            'extraction_method' => 'pdf_text', 'parser_version' => 'test',
        ]);
        $crossTenantProposal = $crossTenantImport->proposals()->create([
            'event_date' => '2026-12-01', 'name' => 'Other tenant proposal', 'event_type' => 'other',
            'instructional_effect' => 'informational', 'included' => true,
        ]);
        $this->actingIn($owner, $tenant)->put("/academic-setup/calendar-imports/{$import->id}/proposals", ['proposals' => [
            $crossTenantProposal->id => ['id' => $crossTenantProposal->id, 'included' => true, 'event_date' => '2026-12-02', 'end_date' => null, 'name' => 'Cross-tenant edit', 'event_type' => 'other', 'instructional_effect' => 'informational'],
        ]])->assertSessionHasErrors(['review', "proposals.{$crossTenantProposal->id}.id"]);
        $this->assertDatabaseHas('calendar_import_proposals', ['id' => $crossTenantProposal->id, 'event_date' => '2026-12-01', 'name' => 'Other tenant proposal']);

        $parent = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $parent->id, 'role' => 'parent', 'status' => 'active']);
        $this->actingIn($parent, $tenant)->put("/academic-setup/calendar-imports/{$import->id}/proposals", ['proposals' => []])->assertForbidden();
    }

    public function test_approval_payload_honors_unsaved_checkboxes_and_excluded_warnings_do_not_block_success(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $year->update(['start_date' => '2026-08-12', 'end_date' => '2027-05-27']);
        $source = $this->calendarSource($owner, $tenant, $year);
        $source->update(['title' => 'Cy-Fair ISD School Calendar']);
        $this->extractor->pages = require base_path('tests/Fixtures/cy-fair-calendar-positioned.php');
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $import = CalendarImport::query()->firstOrFail();
        $invalid = $import->proposals()->create([
            'event_date' => null, 'name' => 'Unusable extracted fragment', 'event_type' => 'other',
            'instructional_effect' => 'informational', 'confidence' => 0.2, 'included' => true,
        ]);
        $selectedIds = $import->proposals()->get()->filter(fn ($proposal) => $proposal->event_date
            && $proposal->event_date->format('Y-m-d') >= '2026-08-12'
            && ($proposal->end_date ?? $proposal->event_date)->format('Y-m-d') <= '2027-05-27')->pluck('id')->all();

        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$import->id}/approve", [
            'replace_previous' => false,
            'update_school_year_dates' => false,
            'included_proposal_ids' => $selectedIds,
        ])->assertRedirect('/calendar')->assertSessionHas(
            'success',
            'Calendar import approved. 15 events were added to the Import Year calendar.',
        );

        $approved = $import->fresh();
        $this->assertSame('approved', $approved->status);
        $this->assertSame($owner->id, $approved->approved_by_user_id);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame(15, $approved->events()->count());
        $this->assertFalse($invalid->fresh()->included);
        $this->assertDatabaseMissing('calendar_events', ['calendar_import_proposal_id' => $invalid->id]);
        $this->assertDatabaseMissing('calendar_events', ['event_date' => '2026-08-03']);
        $this->assertDatabaseMissing('calendar_events', ['event_date' => '2027-05-28']);
        $this->assertDatabaseMissing('calendar_events', ['event_date' => '2027-05-31']);

        $this->actingIn($owner, $tenant)->get("/academic-setup/calendar-imports/{$import->id}")->assertInertia(fn (Assert $page) => $page
            ->where('calendarImport.status', 'approved')
            ->where('calendarImport.events_created_count', 15)
            ->where('calendarImport.included_count', 15)
            ->where('calendarImport.excluded_count', 4)
            ->where('calendarImport.approved_by', $owner->name)
            ->where('canManage', false));
        $this->actingIn($owner, $tenant)->get('/calendar')->assertInertia(fn (Assert $page) => $page
            ->where('calendar.profile.events', fn ($events) => $events->count() === 15
                && $events->contains('name', 'First Day of School')
                && $events->contains('name', 'Last Day of School')));
    }

    public function test_included_invalid_proposal_is_highlighted_and_duplicate_approval_creates_no_events(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $this->extractor->pages = [['page' => 1, 'text' => 'September 7, 2026 - Holiday']];
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $import = CalendarImport::query()->firstOrFail();
        $valid = $import->proposals()->firstOrFail();
        $invalid = $import->proposals()->create([
            'event_date' => null, 'name' => 'Missing date', 'event_type' => 'other',
            'instructional_effect' => 'informational', 'confidence' => 0.2, 'included' => false,
        ]);

        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$import->id}/approve", [
            'replace_previous' => false, 'update_school_year_dates' => false,
            'included_proposal_ids' => [$valid->id, $invalid->id],
        ])->assertSessionHasErrors(['approval', 'proposals.'.$invalid->id]);
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertSame('review_required', $import->fresh()->status);

        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$import->id}/approve", [
            'replace_previous' => false, 'update_school_year_dates' => false,
            'included_proposal_ids' => [$valid->id],
        ])->assertRedirect('/calendar');
        $this->assertDatabaseCount('calendar_events', 1);

        $this->actingIn($owner, $tenant)->put("/academic-setup/calendar-imports/{$import->id}/proposals", ['proposals' => [
            $valid->id => [
                'id' => $valid->id, 'included' => true, 'event_date' => '2026-09-08', 'end_date' => null,
                'name' => 'Too late to edit', 'event_type' => $valid->event_type,
                'instructional_effect' => $valid->instructional_effect,
            ],
        ]])->assertSessionHasErrors('review');
        $this->assertNotSame('Too late to edit', $valid->fresh()->name);

        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$import->id}/approve", [
            'replace_previous' => false, 'update_school_year_dates' => false,
            'included_proposal_ids' => [$valid->id],
        ])->assertSessionHasErrors('approval');
        $this->assertDatabaseCount('calendar_events', 1);
    }

    public function test_approval_transaction_rolls_back_all_events_and_status_when_a_late_write_fails(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $this->extractor->pages = [['page' => 1, 'text' => "September 7, 2026 - Holiday\nOctober 9, 2026 - Teacher Workday"]];
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $import = CalendarImport::query()->firstOrFail();
        $proposalIds = $import->proposals()->pluck('id')->all();
        $importedAuditCount = 0;
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->andReturnUsing(function (string $action) use (&$importedAuditCount): void {
            if ($action === 'calendar-event.imported' && ++$importedAuditCount === 2) {
                throw new \RuntimeException('Simulated late approval failure.');
            }
        });
        $this->app->instance(AuditService::class, $audit);

        $this->actingIn($owner, $tenant);
        try {
            app(CalendarImportService::class)->approve($import, false, false, $proposalIds);
            $this->fail('Approval should have thrown the simulated failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated late approval failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertSame('review_required', $import->fresh()->status);
        $this->assertNull($import->fresh()->approved_at);
    }

    public function test_bulk_review_transaction_rolls_back_every_row_when_a_late_audit_fails(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $this->extractor->pages = [['page' => 1, 'text' => "September 7, 2026 - Holiday\nOctober 9, 2026 - Teacher Workday"]];
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $import = CalendarImport::query()->firstOrFail();
        [$first, $second] = $import->proposals()->get()->values()->all();
        $auditCalls = 0;
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->andReturnUsing(function () use (&$auditCalls): void {
            if (++$auditCalls === 2) {
                throw new \RuntimeException('Simulated late audit failure.');
            }
        });
        $this->app->instance(AuditService::class, $audit);
        $service = $this->app->make(CalendarImportService::class);
        $submitted = [
            $first->id => ['id' => $first->id, 'included' => false, 'event_date' => '2026-09-08', 'end_date' => null, 'name' => 'Changed first', 'event_type' => 'break', 'instructional_effect' => 'informational'],
            $second->id => ['id' => $second->id, 'included' => true, 'event_date' => '2026-10-10', 'end_date' => null, 'name' => 'Changed second', 'event_type' => 'staff_development', 'instructional_effect' => 'non_instructional'],
        ];

        try {
            $service->bulkUpdate($import, $submitted);
            $this->fail('The simulated audit failure should abort the bulk review update.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated late audit failure.', $exception->getMessage());
        }

        $this->assertSame($first->name, $first->fresh()->name);
        $this->assertSame($second->name, $second->fresh()->name);
        $this->assertTrue($first->fresh()->included);
    }

    public function test_review_edits_adds_excludes_and_transactionally_approves_into_the_active_calendar(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $profile = CalendarProfile::create([
            'name' => 'Working calendar', 'academic_year_label' => $year->name,
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'timezone' => $year->timezone,
            'status' => 'active', 'source_type' => 'manual',
        ]);
        $year->academicConfiguration()->create(['calendar_profile_id' => $profile->id, 'status' => 'draft']);
        $profile->events()->create(['event_date' => '2026-10-01', 'event_type' => 'other', 'name' => 'Manual family event', 'instructional_effect' => 'informational', 'status' => 'active']);
        $source = $this->calendarSource($owner, $tenant, $year);
        $this->extractor->pages = $this->districtText();
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $import = CalendarImport::query()->firstOrFail();
        $outside = $import->proposals()->where('event_date', '2027-06-05')->firstOrFail();

        $this->actingIn($owner, $tenant)->patch("/academic-setup/calendar-imports/{$import->id}/proposals/{$outside->id}", [
            'event_date' => $outside->event_date->format('Y-m-d'), 'end_date' => $outside->end_date?->format('Y-m-d'),
            ...$outside->only(['name', 'event_type', 'instructional_effect', 'parser_note']), 'included' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$import->id}/proposals", [
            'event_date' => '2027-03-12', 'end_date' => null, 'name' => 'Added review day',
            'event_type' => 'holiday', 'instructional_effect' => 'non_instructional', 'included' => true,
        ])->assertRedirect();

        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$import->id}/approve", [
            'replace_previous' => false, 'update_school_year_dates' => false,
        ])->assertRedirect('/calendar');

        $this->assertSame('approved', $import->fresh()->status);
        $this->assertDatabaseHas('calendar_events', ['name' => 'Manual family event', 'calendar_import_id' => null, 'status' => 'active']);
        $this->assertDatabaseHas('calendar_events', ['name' => 'Added review day', 'calendar_import_id' => $import->id, 'status' => 'active']);
        $this->assertDatabaseHas('school_years', ['id' => $year->id, 'start_date' => '2026-08-01', 'end_date' => '2027-05-31']);
        $this->actingIn($owner, $tenant)->get('/calendar')->assertInertia(fn (Assert $page) => $page
            ->where('calendar.profile.name', 'Working calendar')
            ->where('calendar.profile.events', fn ($events) => $events->contains('name', 'Thanksgiving Break') && $events->contains('name', 'Early Release'))
            ->where('calendar.summary.removed_days', fn ($days) => $days >= 7));
    }

    public function test_reimport_requires_confirmation_archives_unchanged_imports_and_preserves_manual_edits(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $this->extractor->pages = [['page' => 1, 'text' => "September 7, 2026 - Labor Day Holiday\nOctober 9, 2026 - Teacher Workday"]];
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $first = CalendarImport::query()->firstOrFail();
        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$first->id}/approve", ['replace_previous' => false, 'update_school_year_dates' => false])->assertRedirect();
        $edited = $first->events()->where('name', 'Labor Day Holiday')->firstOrFail();
        $edited->update(['name' => 'Labor Day - locally clarified']);

        $this->extractor->pages = [['page' => 1, 'text' => "November 25, 2026 - Revised Holiday\nFebruary 12, 2027 - Early Release"]];
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $second = CalendarImport::query()->whereKeyNot($first->id)->firstOrFail();
        $this->actingIn($owner, $tenant)->get("/academic-setup/calendar-imports/{$second->id}")->assertInertia(fn (Assert $page) => $page
            ->where('calendarImport.comparison.added', 2)
            ->where('calendarImport.comparison.changed', 0)
            ->where('calendarImport.comparison.removed', 2));
        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$second->id}/approve", ['replace_previous' => false, 'update_school_year_dates' => false])
            ->assertSessionHasErrors('replace_previous');
        $this->assertDatabaseMissing('calendar_events', ['name' => 'Revised Holiday']);

        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$second->id}/approve", ['replace_previous' => true, 'update_school_year_dates' => false])->assertRedirect();
        $this->assertDatabaseHas('calendar_events', ['id' => $edited->id, 'name' => 'Labor Day - locally clarified', 'status' => 'active']);
        $this->assertDatabaseHas('calendar_events', ['name' => 'Teacher Workday', 'status' => 'archived']);
        $this->assertDatabaseHas('calendar_events', ['name' => 'Revised Holiday', 'status' => 'active']);
        $this->assertSame('superseded', $first->fresh()->status);
    }

    public function test_failed_or_out_of_range_extraction_never_creates_live_events(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $this->extractor->pages = [['page' => 1, 'text' => '']];
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports")
            ->assertSessionHasErrors('source');
        $this->assertDatabaseHas('calendar_imports', ['status' => 'failed']);
        $this->assertDatabaseCount('calendar_events', 0);
    }

    public function test_conflicts_roll_back_approval_and_boundary_updates_are_explicit(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $this->extractor->pages = [['page' => 1, 'text' => implode("\n", [
            'August 12, 2026 - First Day of School', 'September 7, 2026 - Holiday',
            'September 7, 2026 - Early Release', 'May 27, 2027 - Last Day of School',
        ])]];
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $import = CalendarImport::query()->firstOrFail();
        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$import->id}/approve", [
            'replace_previous' => false, 'update_school_year_dates' => true,
        ])->assertSessionHasErrors('approval');
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseHas('school_years', ['id' => $year->id, 'start_date' => '2026-08-01', 'end_date' => '2027-05-31']);

        $early = $import->proposals()->where('event_type', 'early_release')->firstOrFail();
        $this->actingIn($owner, $tenant)->patch("/academic-setup/calendar-imports/{$import->id}/proposals/{$early->id}", [
            'event_date' => '2026-09-07', 'end_date' => null, 'name' => $early->name,
            'event_type' => $early->event_type, 'instructional_effect' => $early->instructional_effect,
            'included' => false, 'parser_note' => null,
        ])->assertSessionHasNoErrors();
        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$import->id}/approve", [
            'replace_previous' => false, 'update_school_year_dates' => true,
        ])->assertRedirect('/calendar');
        $this->assertDatabaseHas('school_years', ['id' => $year->id, 'start_date' => '2026-08-12', 'end_date' => '2027-05-27']);
    }

    public function test_import_routes_are_authorized_and_tenant_isolated(): void
    {
        [$ownerA, $tenantA] = $this->tenantUser('owner', 'Tenant A');
        $yearA = $this->schoolYear($tenantA);
        $sourceA = $this->calendarSource($ownerA, $tenantA, $yearA);
        $this->extractor->pages = [['page' => 1, 'text' => 'September 7, 2026 - Holiday']];
        $this->actingIn($ownerA, $tenantA)->post("/academic-setup/sources/{$sourceA->id}/calendar-imports");
        $importA = CalendarImport::query()->firstOrFail();

        $parentA = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenantA->id, 'user_id' => $parentA->id, 'role' => 'parent', 'status' => 'active']);
        $this->actingIn($parentA, $tenantA)->post("/academic-setup/sources/{$sourceA->id}/calendar-imports")->assertForbidden();

        [$parentB, $tenantB] = $this->tenantUser('parent', 'Tenant B');
        $this->actingIn($parentB, $tenantB)->get("/academic-setup/calendar-imports/{$importA->id}")->assertNotFound();
        $this->actingIn($parentB, $tenantB)->post("/academic-setup/calendar-imports/{$importA->id}/approve", ['replace_previous' => false, 'update_school_year_dates' => false])->assertNotFound();
        $this->actingIn($parentB, $tenantB)->put("/academic-setup/calendar-imports/{$importA->id}/proposals", ['proposals' => []])->assertNotFound();
    }

    public function test_approval_never_writes_tenant_events_into_a_shared_calendar_profile(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $sharedId = DB::table('calendar_profiles')->insertGetId([
            'tenant_id' => null, 'education_provider_id' => null, 'ownership_key' => 'shared',
            'name' => 'Shared provider calendar', 'academic_year_label' => $year->name,
            'start_date' => $year->start_date, 'end_date' => $year->end_date,
            'timezone' => $year->timezone, 'status' => 'active', 'source_type' => 'provider',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('calendar_events')->insert([
            'calendar_profile_id' => $sharedId, 'event_date' => '2026-09-01',
            'event_type' => 'other', 'name' => 'Shared baseline event',
            'instructional_effect' => 'informational', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $year->academicConfiguration()->create(['calendar_profile_id' => $sharedId, 'status' => 'draft']);
        $source = $this->calendarSource($owner, $tenant, $year);
        $this->extractor->pages = [['page' => 1, 'text' => 'September 7, 2026 - Labor Day Holiday']];

        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $import = CalendarImport::query()->firstOrFail();
        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$import->id}/approve", [
            'replace_previous' => false, 'update_school_year_dates' => false,
        ])->assertRedirect('/calendar');

        $event = $import->events()->firstOrFail();
        $this->assertNotSame($sharedId, $event->calendar_profile_id);
        $this->assertSame($tenant->id, $event->calendarProfile->tenant_id);
        $this->assertDatabaseHas('calendar_events', ['calendar_profile_id' => $sharedId, 'name' => 'Shared baseline event']);
        $this->assertDatabaseMissing('calendar_events', ['calendar_profile_id' => $sharedId, 'name' => 'Labor Day Holiday']);
        $this->assertSame($event->calendar_profile_id, $year->academicConfiguration->fresh()->calendar_profile_id);
    }

    public function test_owner_deletes_only_the_selected_unapproved_import_and_its_proposals(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $profile = CalendarProfile::create([
            'name' => 'Preserved calendar', 'academic_year_label' => $year->name,
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'timezone' => $year->timezone,
            'status' => 'active', 'source_type' => 'manual',
        ]);
        $year->academicConfiguration()->create(['calendar_profile_id' => $profile->id, 'status' => 'draft']);
        $manualEvent = $profile->events()->create([
            'event_date' => '2026-10-01', 'event_type' => 'other', 'name' => 'Manual event',
            'instructional_effect' => 'informational', 'status' => 'active',
        ]);
        $source = $this->calendarSource($owner, $tenant, $year);
        $storedFile = $source->currentFile;
        $this->extractor->pages = [['page' => 1, 'text' => 'September 7, 2026 - Holiday']];
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $discarded = CalendarImport::query()->firstOrFail();
        $proposalIds = $discarded->proposals()->pluck('id');
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $kept = CalendarImport::query()->whereKeyNot($discarded->id)->firstOrFail();
        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$kept->id}/approve", [
            'replace_previous' => false, 'update_school_year_dates' => false,
        ])->assertRedirect();
        $otherImportedEvent = $kept->events()->firstOrFail();

        $this->actingIn($owner, $tenant)->delete("/academic-setup/sources/{$source->id}/calendar-imports/{$discarded->id}")
            ->assertRedirect(route('academic.sources.show', $source))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('calendar_imports', ['id' => $discarded->id]);
        foreach ($proposalIds as $proposalId) {
            $this->assertDatabaseMissing('calendar_import_proposals', ['id' => $proposalId]);
        }
        $this->assertDatabaseHas('calendar_imports', ['id' => $kept->id]);
        $this->assertDatabaseHas('academic_sources', ['id' => $source->id]);
        $this->assertDatabaseHas('calendar_profiles', ['id' => $profile->id]);
        $this->assertDatabaseHas('calendar_events', ['id' => $manualEvent->id, 'name' => 'Manual event']);
        $this->assertDatabaseHas('calendar_events', ['id' => $otherImportedEvent->id, 'calendar_import_id' => $kept->id]);
        $this->assertDatabaseHas('academic_source_files', ['id' => $storedFile->id]);
        Storage::disk($storedFile->disk)->assertExists($storedFile->stored_path);

        $this->actingIn($owner, $tenant)->get("/academic-setup/sources/{$source->id}")->assertInertia(fn (Assert $page) => $page
            ->has('calendarSetup.imports', 1)
            ->where('calendarSetup.imports.0.id', $kept->id)
            ->where('calendarSetup.imports.0.proposals_count', 1)
            ->where('calendarSetup.imports.0.linked_events_count', 1)
            ->where('calendarSetup.imports.0.can_delete', false));
    }

    public function test_teacher_can_delete_manual_handling_import_but_parent_cannot(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $import = CalendarImport::create([
            'academic_source_id' => $source->id, 'academic_source_file_id' => $source->currentFile->id,
            'school_year_id' => $year->id, 'created_by_user_id' => $owner->id,
            'status' => 'manual_handling', 'extraction_method' => 'pdf_text', 'parser_version' => 'general-text-v2',
        ]);
        $import->proposals()->create([
            'name' => 'Legacy unusable row', 'event_type' => 'other', 'instructional_effect' => 'informational',
            'confidence' => 0.35, 'included' => false,
        ]);
        $parent = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $parent->id, 'role' => 'parent', 'status' => 'active']);
        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'status' => 'active']);

        $this->actingIn($parent, $tenant)->delete("/academic-setup/sources/{$source->id}/calendar-imports/{$import->id}")->assertForbidden();
        $this->assertDatabaseHas('calendar_imports', ['id' => $import->id]);
        $this->actingIn($teacher, $tenant)->delete("/academic-setup/sources/{$source->id}/calendar-imports/{$import->id}")->assertRedirect();
        $this->assertDatabaseMissing('calendar_imports', ['id' => $import->id]);
        $this->assertDatabaseMissing('calendar_import_proposals', ['calendar_import_id' => $import->id]);
    }

    public function test_simple_delete_blocks_approved_import_and_preserves_every_linked_event(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        $year = $this->schoolYear($tenant);
        $source = $this->calendarSource($owner, $tenant, $year);
        $this->extractor->pages = [['page' => 1, 'text' => 'September 7, 2026 - Holiday']];
        $this->actingIn($owner, $tenant)->post("/academic-setup/sources/{$source->id}/calendar-imports");
        $approved = CalendarImport::query()->firstOrFail();
        $this->actingIn($owner, $tenant)->post("/academic-setup/calendar-imports/{$approved->id}/approve", [
            'replace_previous' => false, 'update_school_year_dates' => false,
        ])->assertRedirect();
        $importedEvent = $approved->events()->firstOrFail();
        $manualEvent = $approved->fresh()->calendarProfile->events()->create([
            'event_date' => '2026-10-01', 'event_type' => 'other', 'name' => 'Manual preserved event',
            'instructional_effect' => 'informational', 'status' => 'active',
        ]);

        $this->actingIn($owner, $tenant)->delete("/academic-setup/sources/{$source->id}/calendar-imports/{$approved->id}")
            ->assertSessionHasErrors('calendar_import');

        $this->assertDatabaseHas('calendar_imports', ['id' => $approved->id, 'status' => 'approved']);
        $this->assertDatabaseHas('calendar_events', ['id' => $importedEvent->id, 'calendar_import_id' => $approved->id]);
        $this->assertDatabaseHas('calendar_events', ['id' => $manualEvent->id, 'calendar_import_id' => null]);
        $this->actingIn($owner, $tenant)->get("/academic-setup/sources/{$source->id}")->assertInertia(fn (Assert $page) => $page
            ->where('calendarSetup.imports.0.can_delete', false)
            ->where('calendarSetup.imports.0.linked_events_count', 1));
    }

    public function test_delete_is_source_bound_tenant_isolated_and_stale_ids_are_safe(): void
    {
        [$ownerA, $tenantA] = $this->tenantUser('owner', 'Tenant A');
        $yearA = $this->schoolYear($tenantA);
        $sourceA = $this->calendarSource($ownerA, $tenantA, $yearA);
        $otherSourceA = $this->calendarSource($ownerA, $tenantA, $yearA);
        $this->extractor->pages = [['page' => 1, 'text' => 'September 7, 2026 - Holiday']];
        $this->actingIn($ownerA, $tenantA)->post("/academic-setup/sources/{$sourceA->id}/calendar-imports");
        $import = CalendarImport::query()->firstOrFail();

        $this->actingIn($ownerA, $tenantA)->delete("/academic-setup/sources/{$otherSourceA->id}/calendar-imports/{$import->id}")->assertNotFound();
        $this->assertDatabaseHas('calendar_imports', ['id' => $import->id]);

        [$ownerB, $tenantB] = $this->tenantUser('owner', 'Tenant B');
        $this->actingIn($ownerB, $tenantB)->delete("/academic-setup/sources/{$sourceA->id}/calendar-imports/{$import->id}")->assertNotFound();
        $this->assertDatabaseHas('calendar_imports', ['id' => $import->id]);

        $this->actingIn($ownerA, $tenantA)->delete("/academic-setup/sources/{$sourceA->id}/calendar-imports/{$import->id}")->assertRedirect();
        $this->actingIn($ownerA, $tenantA)->delete("/academic-setup/sources/{$sourceA->id}/calendar-imports/{$import->id}")->assertNotFound();
    }

    private function districtText(): array
    {
        return [['page' => 1, 'text' => implode("\n", [
            'August 12, 2026 - First Day of School', 'September 7, 2026 - Labor Day Holiday',
            'October 9, 2026 - Teacher Workday', 'Thanksgiving Break: November 23-27, 2026',
            'January 4, 2027 - Student Holiday', 'February 12, 2027 - Early Release',
            'May 27, 2027 - Last Day of School', 'June 5, 2027 - Summer Holiday',
        ])]];
    }

    private function tenantUser(string $role = 'owner', string $name = 'Import Academy'): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => $name, 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);
        app(TenantContext::class)->set($tenant, $membership);
        return [$user, $tenant];
    }

    private function schoolYear(Tenant $tenant): SchoolYear
    {
        return $tenant->schoolYears()->create([
            'name' => 'Import Year', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31',
            'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => 180,
            'instructional_week_type' => 'five_day', 'instructional_weekdays' => [1, 2, 3, 4, 5],
        ]);
    }

    private function calendarSource(User $owner, Tenant $tenant, SchoolYear $year): AcademicSource
    {
        $this->actingIn($owner, $tenant)->post('/academic-setup/sources', [
            'title' => 'District Calendar PDF', 'description' => 'Fixture calendar for review.',
            'source_kind' => 'upload', 'source_category' => 'calendar', 'authority_level' => 'official_provider',
            'education_provider_id' => null, 'school_year_id' => $year->id, 'grade_level_id' => null,
            'version_label' => 'v1', 'academic_year_label' => $year->name, 'publication_date' => '2026-07-01',
            'source_url' => null, 'notes' => null, 'source_file' => $this->pdf('district-calendar.pdf'),
        ])->assertRedirect();
        return AcademicSource::query()->latest('id')->firstOrFail();
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    }

    private function pdf(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'calendar-import-pdf-');
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
