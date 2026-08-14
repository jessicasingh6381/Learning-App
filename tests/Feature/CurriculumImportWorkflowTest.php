<?php

namespace Tests\Feature;

use App\Contracts\PdfTextExtractor;
use App\Models\AcademicSource;
use App\Models\AcademicYearConfiguration;
use App\Models\CalendarProfile;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CurriculumImport;
use App\Models\CurriculumImportProposal;
use App\Models\CurriculumPackage;
use App\Models\CurriculumPackageCourse;
use App\Models\CurriculumPeriod;
use App\Models\CurriculumUnit;
use App\Models\CurriculumUnitComponent;
use App\Models\CurriculumUnitStandardAlignment;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\StandardsFramework;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CurriculumImportService;
use App\Services\CurriculumImportSchedulingService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class CurriculumImportWorkflowTest extends TestCase
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

    public function test_reviewed_pdf_extracts_generic_hierarchical_proposals_from_current_file_idempotently(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        [$source, $mapping] = $this->curriculumContext($owner, $tenant);
        $this->extractor->pages = require base_path('tests/Fixtures/cfisd-grade5-math-yag-positioned.php');
        $source->currentFile->update(['is_current' => false, 'current_key' => null]);
        Storage::disk('local')->put("academic-sources/{$source->id}/math-v2.pdf", '%PDF-1.4 current fixture');
        $currentFile = $source->files()->create(['uploaded_by_user_id' => $owner->id, 'version_number' => 2, 'current_key' => 'current', 'is_current' => true, 'disk' => 'local', 'stored_path' => "academic-sources/{$source->id}/math-v2.pdf", 'stored_filename' => 'math-v2.pdf', 'original_filename' => '5th - Math revised.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 24, 'checksum_sha256' => str_repeat('b', 64), 'uploaded_at' => now()]);
        $source->unsetRelation('currentFile');

        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), [
            'curriculum_package_course_id' => $mapping->id,
        ])->assertRedirect();
        $import = CurriculumImport::query()->firstOrFail();

        $this->assertSame('review', $import->status);
        $this->assertSame('cfisd-grade5-math-yag-v1', $import->parser_version);
        $this->assertSame($currentFile->id, $import->academic_source_file_id);
        $this->assertSame('2026-05-26', $import->source_revision_date->format('Y-m-d'));
        $this->assertSame(4, $import->proposals()->where('proposal_type', 'period')->count());
        $this->assertSame(8, $import->proposals()->where('proposal_type', 'unit')->count());
        $this->assertSame(5, $import->proposals()->where('proposal_type', 'assessment')->count());
        $bridge = $import->proposals()->where('name', 'Bridge to 5th Grade')->firstOrFail();
        $this->assertSame('transition', $bridge->unit_type);
        $this->assertLessThan(.8, $bridge->confidence);
        $this->assertStringContainsString('preserved exactly', $bridge->parser_note);
        $this->assertSame(['5.1A', '5.1B', '5.1C', '5.1D', '5.1E', '5.1F', '5.1G'], $import->proposals()->where('name', 'Launch into 5th Grade')->firstOrFail()->standard_codes);
        $this->assertDatabaseCount('curriculum_periods', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'curriculum-import.extracted', 'auditable_id' => (string) $import->id]);
        $this->assertSame('completed', $source->fresh()->processing_status);

        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), [
            'curriculum_package_course_id' => $mapping->id,
        ])->assertRedirect(route('academic.curriculum-imports.show', $import));
        $this->assertDatabaseCount('curriculum_imports', 1);

        $this->actingIn($owner, $tenant)->get(route('academic.curriculum-imports.show', $import))->assertInertia(fn (Assert $page) => $page
            ->component('Academic/CurriculumImports/Show')->has('periods', 4)
            ->where('curriculumImport.parser_version', 'cfisd-grade5-math-yag-v1')
            ->where('canManage', true));
    }

    public function test_start_rejects_ineligible_sources_unauthorized_roles_and_cross_tenant_targets(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        [$source, $mapping] = $this->curriculumContext($owner, $tenant);
        $this->extractor->pages = require base_path('tests/Fixtures/cfisd-grade5-math-yag-positioned.php');
        $source->update(['review_status' => 'unreviewed']);
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), ['curriculum_package_course_id' => $mapping->id])
            ->assertSessionHasErrors('source');
        $source->update(['review_status' => 'reviewed', 'archived_at' => now()]);
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), ['curriculum_package_course_id' => $mapping->id])
            ->assertSessionHasErrors('source');
        $source->update(['archived_at' => null]);

        foreach (['parent', 'tutor'] as $role) {
            $user = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);
            $this->actingIn($user, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), ['curriculum_package_course_id' => $mapping->id])->assertForbidden();
        }

        [$otherOwner, $otherTenant] = $this->tenantUser('owner', 'Other Curriculum Tenant');
        [, $foreignMapping] = $this->curriculumContext($otherOwner, $otherTenant);
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), ['curriculum_package_course_id' => $foreignMapping->id])->assertNotFound();
        $this->assertDatabaseCount('curriculum_imports', 0);
    }

    public function test_teacher_can_manage_but_student_is_denied_and_import_binding_is_tenant_safe(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        [$source, $mapping] = $this->curriculumContext($owner, $tenant);
        $this->extractor->pages = require base_path('tests/Fixtures/cfisd-grade5-math-yag-positioned.php');
        $teacher = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'status' => 'active']);
        $this->actingIn($teacher, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), ['curriculum_package_course_id' => $mapping->id])->assertRedirect();
        $import = CurriculumImport::query()->firstOrFail();

        $student = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $student->id, 'role' => 'student', 'status' => 'active']);
        $this->actingIn($student, $tenant)->get(route('academic.curriculum-imports.show', $import))->assertForbidden();

        [$otherOwner, $otherTenant] = $this->tenantUser('owner', 'Isolated Tenant');
        $this->actingIn($otherOwner, $otherTenant)->get('/academic-setup/curriculum-imports/'.$import->id)->assertNotFound();
    }

    public function test_parser_diagnostics_and_grade_framework_provider_compatibility_are_enforced(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        [$source, $mapping] = $this->curriculumContext($owner, $tenant);
        $pages = require base_path('tests/Fixtures/cfisd-grade5-math-yag-positioned.php');
        $pages[0]['text'] = "Grade 5 Math Year at a Glance 2026-2027\n1st Nine Weeks\nAssessments at a Glance";
        $pages[0]['items'] = collect($pages[0]['items'])->reject(fn ($item) => preg_match('/^(?:2nd|3rd|4th) Nine Weeks/', $item['text']))->values()->all();
        $this->extractor->pages = $pages;
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), ['curriculum_package_course_id' => $mapping->id])->assertRedirect();
        $import = CurriculumImport::query()->firstOrFail();
        $this->assertNotNull($import->diagnostic);
        $this->assertStringContainsString('Extraction needs review', $import->diagnostic);

        $source->currentFile->update(['is_current' => false, 'current_key' => null]);
        Storage::disk('local')->put("academic-sources/{$source->id}/math-new.pdf", '%PDF new fixture');
        $source->files()->create(['uploaded_by_user_id' => $owner->id, 'version_number' => 2, 'current_key' => 'current', 'is_current' => true, 'disk' => 'local', 'stored_path' => "academic-sources/{$source->id}/math-new.pdf", 'stored_filename' => 'math-new.pdf', 'original_filename' => 'math-new.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 16, 'checksum_sha256' => str_repeat('c', 64), 'uploaded_at' => now()]);
        $source->unsetRelation('currentFile');
        $otherGrade = GradeLevel::create(['code' => '6', 'name' => 'Grade 6', 'sort_order' => 6, 'is_active' => true]);
        $mapping->update(['grade_level_id' => $otherGrade->id]);
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), ['curriculum_package_course_id' => $mapping->id])->assertSessionHasErrors('curriculum_package_course_id');
        $mapping->update(['grade_level_id' => $source->grade_level_id]);

        $otherFramework = StandardsFramework::create(['name' => 'Other standards', 'short_name' => 'OTHER', 'jurisdiction' => 'Other', 'version_label' => '1', 'status' => 'active']);
        $source->links()->where('link_type', 'standards_framework')->update(['link_id' => $otherFramework->id]);
        $source->unsetRelation('links');
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), ['curriculum_package_course_id' => $mapping->id])->assertSessionHasErrors('curriculum_package_course_id');
        $source->links()->where('link_type', 'standards_framework')->update(['link_id' => $mapping->curriculumPackage->standards_framework_id]);
        $source->unsetRelation('links');
        $otherProvider = EducationProvider::create(['name' => 'Other provider', 'short_name' => 'OTHER', 'provider_type' => 'publisher', 'country_code' => 'US', 'status' => 'active']);
        $mapping->curriculumPackage->update(['education_provider_id' => $otherProvider->id]);
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), ['curriculum_package_course_id' => $mapping->id])->assertSessionHasErrors('curriculum_package_course_id');
    }

    public function test_focused_source_action_creates_and_reuses_compatible_setup_without_client_ids(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        [$source, $mapping] = $this->curriculumContext($owner, $tenant);
        $courseId = $mapping->course_id;
        $packageId = $mapping->curriculum_package_id;
        $mapping->delete();
        CurriculumPackage::query()->findOrFail($packageId)->delete();
        Course::query()->findOrFail($courseId)->delete();
        $this->extractor->pages = require base_path('tests/Fixtures/cfisd-grade5-math-yag-positioned.php');

        $this->actingIn($owner, $tenant)->get(route('academic.sources.show', $source))
            ->assertInertia(fn (Assert $page) => $page
                ->where('curriculumSetup.workflow_state', 'unknown')
                ->where('curriculumSetup.primary_action_label', 'Check outline support')
                ->where('curriculumSetup.primary_action_method', 'post')
                ->where('curriculumSetup.subject.name', 'Mathematics'));
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-capability.store', $source))->assertRedirect();
        $this->actingIn($owner, $tenant)->get(route('academic.sources.show', $source))
            ->assertInertia(fn (Assert $page) => $page
                ->where('curriculumSetup.workflow_state', 'ready')
                ->where('curriculumSetup.primary_action_label', 'Create curriculum outline'));

        $response = $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source));
        $import = CurriculumImport::query()->firstOrFail();
        $response->assertRedirect(route('academic.curriculum-imports.show', $import));
        $this->assertDatabaseCount('curriculum_packages', 1);
        $this->assertDatabaseCount('courses', 1);
        $this->assertDatabaseCount('curriculum_package_courses', 1);
        $this->assertSame($source->currentFile->id, $import->academic_source_file_id);
        $this->assertDatabaseHas('academic_source_links', [
            'academic_source_id' => $source->id,
            'link_type' => 'curriculum_package',
            'link_id' => $import->curriculum_package_id,
        ]);
        foreach (['curriculum-package.created', 'course.created', 'curriculum-package.course-added', 'curriculum-import.extracted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }

        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source))
            ->assertRedirect(route('academic.curriculum-imports.show', $import));
        $this->assertDatabaseCount('curriculum_packages', 1);
        $this->assertDatabaseCount('courses', 1);
        $this->assertDatabaseCount('curriculum_package_courses', 1);
        $this->assertDatabaseCount('curriculum_imports', 1);

        $this->actingIn($owner, $tenant)->get(route('academic.sources.show', $source))
            ->assertInertia(fn (Assert $page) => $page
                ->where('curriculumSetup.workflow_state', 'review')
                ->where('curriculumSetup.primary_action_label', 'Review curriculum outline')
                ->where('curriculumSetup.primary_action_method', 'get'));
    }

    public function test_automatic_setup_ignores_incompatible_candidates_and_rolls_back_everything_on_failure(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        [$source, $mapping] = $this->curriculumContext($owner, $tenant);
        $incompatiblePackage = $mapping->curriculumPackage;
        $otherProvider = EducationProvider::create(['name' => 'Other provider', 'short_name' => 'OTHER', 'provider_type' => 'publisher', 'country_code' => 'US', 'status' => 'active']);
        $incompatiblePackage->update(['education_provider_id' => $otherProvider->id]);
        $this->extractor->pages = require base_path('tests/Fixtures/cfisd-grade5-math-yag-positioned.php');

        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source))->assertRedirect();
        $import = CurriculumImport::query()->firstOrFail();
        $this->assertNotSame($incompatiblePackage->id, $import->curriculum_package_id);
        $this->assertSame($mapping->course_id, $import->packageCourse->course_id);

        [$owner2, $tenant2] = $this->tenantUser('owner', 'Rollback Curriculum Tenant');
        [$source2, $mapping2] = $this->curriculumContext($owner2, $tenant2);
        $courseId = $mapping2->course_id;
        $packageId = $mapping2->curriculum_package_id;
        $mapping2->delete();
        CurriculumPackage::query()->findOrFail($packageId)->delete();
        Course::query()->findOrFail($courseId)->delete();
        $beforeAudits = AuditLog::query()->count();
        $this->extractor->pages = [];

        $this->actingIn($owner2, $tenant2)->post(route('academic.sources.curriculum-imports.store', $source2))
            ->assertSessionHasErrors('source');
        $this->assertDatabaseCount('curriculum_imports', 1);
        $this->assertSame(0, CurriculumPackage::query()->count());
        $this->assertSame(0, Course::query()->count());
        $this->assertSame(0, CurriculumPackageCourse::query()->count());
        $this->assertSame($beforeAudits, AuditLog::query()->count());
        $this->assertSame('unprocessed', $source2->fresh()->processing_status);
    }

    public function test_bulk_review_is_atomic_row_specific_preserves_evidence_and_guards_stale_approval(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        [$source, $mapping] = $this->curriculumContext($owner, $tenant);
        $import = $this->start($owner, $tenant, $source, $mapping);
        $payload = $this->reviewPayload($import);
        $unit = $import->proposals()->where('proposal_type', 'unit')->firstOrFail();
        $originalEvidence = $unit->only(['raw_text', 'source_page', 'parser_note', 'original_values']);
        $payload[$unit->id]['name'] = 'Locally clarified unit';
        $payload[$unit->id]['standard_codes'] = ['5.1A', '5.1B'];

        $this->actingIn($owner, $tenant)->put(route('academic.curriculum-imports.proposals.bulk-update', $import), ['proposals' => $payload])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Locally clarified unit', $unit->fresh()->name);
        $this->assertTrue($unit->fresh()->manually_edited);
        $this->assertSame($originalEvidence, $unit->fresh()->only(['raw_text', 'source_page', 'parser_note', 'original_values']));
        $this->assertSame(1, $import->fresh()->review_version);

        $payload = $this->reviewPayload($import->fresh());
        $first = $import->proposals()->where('proposal_type', 'unit')->firstOrFail();
        $second = $import->proposals()->where('proposal_type', 'unit')->skip(1)->firstOrFail();
        $before = $first->name;
        $payload[$first->id]['name'] = 'Must roll back';
        $payload[$second->id]['planned_start_date'] = '2026-10-20';
        $payload[$second->id]['planned_end_date'] = '2026-10-19';
        $this->actingIn($owner, $tenant)->put(route('academic.curriculum-imports.proposals.bulk-update', $import), ['proposals' => $payload])
            ->assertSessionHasErrors("proposals.{$second->id}.planned_end_date");
        $this->assertSame($before, $first->fresh()->name);

        $payload = $this->reviewPayload($import->fresh());
        $payload[$first->id]['name'] = 'First late-audit edit';
        $payload[$second->id]['name'] = 'Second late-audit edit';
        $auditCalls = 0;
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->andReturnUsing(function () use (&$auditCalls): void {
            if (++$auditCalls === 2) throw new \RuntimeException('Simulated bulk audit failure.');
        });
        $this->app->instance(AuditService::class, $audit);
        $this->actingIn($owner, $tenant);
        try {
            app(CurriculumImportService::class)->bulkUpdate($import, $payload);
            $this->fail('Bulk save should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated bulk audit failure.', $exception->getMessage());
        }
        $this->assertSame($before, $first->fresh()->name);
        $this->assertNotSame('Second late-audit edit', $second->fresh()->name);
        $this->assertSame(1, $import->fresh()->review_version);
        $this->app->instance(AuditService::class, new AuditService());

        $this->actingIn($owner, $tenant)->post(route('academic.curriculum-imports.approve', $import), ['review_version' => 0])
            ->assertSessionHasErrors('approval');
        $this->assertDatabaseCount('curriculum_periods', 0);
        $source->update(['archived_at' => now()]);
        $this->actingIn($owner, $tenant)->put(route('academic.curriculum-imports.proposals.bulk-update', $import), ['proposals' => $this->reviewPayload($import)])
            ->assertSessionHasErrors('review');
        $this->actingIn($owner, $tenant)->post(route('academic.curriculum-imports.approve', $import), ['review_version' => 1])
            ->assertSessionHasErrors('approval');
    }

    public function test_approval_materializes_ordered_typed_outline_alignments_provenance_and_becomes_read_only(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        [$source, $mapping] = $this->curriculumContext($owner, $tenant);
        $import = $this->start($owner, $tenant, $source, $mapping);

        $this->actingIn($owner, $tenant)->post(route('academic.curriculum-imports.approve', $import), ['review_version' => 0])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $import->fresh()->status);
        $this->assertSame(4, CurriculumPeriod::query()->where('curriculum_package_course_id', $mapping->id)->count());
        $this->assertSame(13, CurriculumUnit::query()->where('curriculum_package_course_id', $mapping->id)->count());
        $this->assertDatabaseHas('curriculum_units', ['name' => 'STAAR Review', 'unit_type' => 'review', 'curriculum_import_id' => $import->id]);
        $this->assertDatabaseHas('curriculum_units', ['name' => 'Bridge to 5th Grade', 'unit_type' => 'transition', 'academic_source_file_id' => $source->currentFile->id]);
        $this->assertDatabaseHas('curriculum_units', ['name' => 'Math STAAR', 'unit_type' => 'assessment']);
        $this->assertDatabaseHas('curriculum_unit_standard_alignments', ['standard_code' => '5.1G', 'normalized_code' => '5.1G', 'curriculum_import_id' => $import->id]);
        $alignment = CurriculumUnitStandardAlignment::query()->firstOrFail();
        $this->assertNotNull($alignment->curriculum_import_proposal_id);
        $this->assertNotNull($alignment->source_raw_text);
        $this->assertDatabaseHas('audit_logs', ['action' => 'curriculum-import.approved', 'auditable_id' => (string) $import->id]);

        $this->actingIn($owner, $tenant)->put(route('academic.curriculum-imports.proposals.bulk-update', $import), ['proposals' => $this->reviewPayload($import)])
            ->assertSessionHasErrors('review');
        $this->actingIn($owner, $tenant)->post(route('academic.curriculum-imports.approve', $import), ['review_version' => 0])
            ->assertSessionHasErrors('approval');
        $this->assertSame(13, CurriculumUnit::query()->where('curriculum_package_course_id', $mapping->id)->count());
    }

    public function test_draft_and_empty_target_rules_and_late_failure_roll_back_all_materialization(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        [$source, $mapping] = $this->curriculumContext($owner, $tenant);
        $import = $this->start($owner, $tenant, $source, $mapping);
        $mapping->curriculumPackage->update(['status' => 'active']);
        $this->actingIn($owner, $tenant)->post(route('academic.curriculum-imports.approve', $import), ['review_version' => 0])->assertSessionHasErrors('curriculum_package_course_id');
        $mapping->curriculumPackage->update(['status' => 'draft']);

        $calls = 0;
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->andReturnUsing(function (string $action) use (&$calls): void {
            if ($action === 'curriculum-unit.imported' && ++$calls === 2) throw new \RuntimeException('Simulated late curriculum write failure.');
        });
        $this->app->instance(AuditService::class, $audit);
        $this->actingIn($owner, $tenant);
        try {
            app(CurriculumImportService::class)->approve($import, 0);
            $this->fail('Approval should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated late curriculum write failure.', $exception->getMessage());
        }
        $this->assertDatabaseCount('curriculum_periods', 0);
        $this->assertDatabaseCount('curriculum_units', 0);
        $this->assertSame('review', $import->fresh()->status);

        $periodProposal = $import->proposals()->where('proposal_type', 'period')->firstOrFail();
        CurriculumPeriod::create([
            'curriculum_package_course_id' => $mapping->id, 'name' => 'Existing outline', 'sequence' => 99,
            'status' => 'draft', 'academic_source_id' => $source->id, 'academic_source_file_id' => $source->currentFile->id,
            'curriculum_import_id' => $import->id, 'curriculum_import_proposal_id' => $periodProposal->id,
            'parser_key' => $import->parser_key, 'parser_version' => $import->parser_version,
        ]);
        $this->app->instance(AuditService::class, new AuditService());
        $this->actingIn($owner, $tenant)->post(route('academic.curriculum-imports.approve', $import), ['review_version' => 0])
            ->assertSessionHasErrors('approval');
    }

    public function test_elar_import_bulk_reviews_and_materializes_hierarchical_components_with_provenance(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        [$source, $mapping] = $this->curriculumContext($owner, $tenant, 'ELAR');
        $courseId = $mapping->course_id;
        $packageId = $mapping->curriculum_package_id;
        $mapping->delete();
        CurriculumPackage::query()->findOrFail($packageId)->delete();
        Course::query()->findOrFail($courseId)->delete();
        $this->extractor->pages = require base_path('tests/Fixtures/cfisd-grade5-elar-yag-positioned.php');
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source))->assertRedirect();
        $import = CurriculumImport::query()->firstOrFail();
        $mapping = $import->packageCourse()->with(['course', 'curriculumPackage'])->firstOrFail();
        $this->assertSame('cfisd-grade5-elar-yag-parent-v3', $import->parser_version);
        $this->assertSame('Grade 5 English Language Arts and Reading', $mapping->course->name);
        $this->assertSame('CFISD Grade 5 Curriculum', $mapping->curriculumPackage->name);
        $this->assertSame(4, $import->proposals()->where('proposal_type', 'period')->count());
        $this->assertSame(12, $import->proposals()->where('proposal_type', 'unit')->count());
        $this->assertSame(6, $import->proposals()->where('proposal_type', 'assessment')->count());
        $componentCount = $import->proposals()->where('proposal_type', 'component')->count();
        $this->assertGreaterThan(100, $componentCount);
        $this->assertDatabaseCount('curriculum_unit_components', 0);

        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source))->assertRedirect(route('academic.curriculum-imports.show', $import));
        $this->assertDatabaseCount('curriculum_imports', 1);
        $this->actingIn($owner, $tenant)->get(route('academic.curriculum-imports.show', $import))->assertInertia(fn (Assert $page) => $page
            ->has('periods', 4)->where('context.course', 'Grade 5 English Language Arts and Reading')
            ->where('periods.0.children.0.name', 'Unit 1')->where('periods.0.children.0.children.0.proposal_type', 'component'));

        $firstGenerationIds = $import->proposals()->pluck('id');
        $this->actingIn($owner, $tenant)->post(route('academic.curriculum-imports.reextract', $import))->assertSessionHasNoErrors();
        $this->assertSame($componentCount, $import->proposals()->where('proposal_type', 'component')->count());
        $this->assertSame(0, $import->proposals()->whereIn('id', $firstGenerationIds)->count());
        $this->assertSame($firstGenerationIds->count(), CurriculumImportProposal::withoutGlobalScope('active_generation')->whereIn('id', $firstGenerationIds)->whereNotNull('superseded_at')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'curriculum-import.reextracted']);

        $component = $import->proposals()->where('proposal_type', 'component')->where('name', 'Central Ideas')->firstOrFail();
        $unitProposal = $import->proposals()->where('proposal_type', 'unit')->where('name', 'Unit 1')->firstOrFail();
        $payload = $this->reviewPayload($import);
        $payload[$component->id]['description'] = 'Reviewed instructional emphasis';
        $payload[$unitProposal->id]['summary'] = 'Reading: reviewed module summary · Writing: reviewed module summary';
        $this->actingIn($owner, $tenant)->put(route('academic.curriculum-imports.proposals.bulk-update', $import), ['proposals' => $payload])->assertSessionHasNoErrors();
        $this->assertSame('Reviewed instructional emphasis', $component->fresh()->description);
        $this->assertSame('Reading: reviewed module summary · Writing: reviewed module summary', $unitProposal->fresh()->summary);
        $this->assertTrue($component->fresh()->manually_edited);
        $reviewVersion = $import->fresh()->review_version;

        $invalid = $this->reviewPayload($import);
        $other = $import->proposals()->where('proposal_type', 'component')->where('parent_proposal_id', $component->parent_proposal_id)->whereKeyNot($component->id)->firstOrFail();
        $invalid[$other->id]['sequence'] = $invalid[$component->id]['sequence'];
        $invalid[$component->id]['name'] = 'Must roll back';
        $invalidResponse = $this->actingIn($owner, $tenant)->put(route('academic.curriculum-imports.proposals.bulk-update', $import), ['proposals' => $invalid]);
        $invalidResponse->assertSessionHasErrors('review');
        $this->assertTrue(collect(session('errors')->getBag('default')->keys())->contains(fn ($key) => str_starts_with($key, 'proposals.') && str_ends_with($key, '.sequence')));
        $this->assertSame('Central Ideas', $component->fresh()->name);
        $this->actingIn($owner, $tenant)->post(route('academic.curriculum-imports.approve', $import), ['review_version' => $reviewVersion - 1])->assertSessionHasErrors('approval');

        $this->actingIn($owner, $tenant)->post(route('academic.curriculum-imports.approve', $import), ['review_version' => $reviewVersion])->assertSessionHasNoErrors();
        $this->assertSame(18, CurriculumUnit::query()->where('curriculum_package_course_id', $mapping->id)->count());
        $this->assertSame($componentCount, CurriculumUnitComponent::query()->count());
        $child = CurriculumUnitComponent::query()->whereNotNull('parent_component_id')->firstOrFail();
        $this->assertSame($child->curriculum_unit_id, $child->parent->curriculum_unit_id);
        $this->assertTrue(CurriculumUnitComponent::query()->get()->every(fn ($record) => $record->academic_source_id === $source->id
            && $record->academic_source_file_id === $source->currentFile->id && $record->curriculum_import_id === $import->id
            && $record->curriculum_import_proposal_id && $record->source_page && $record->source_raw_text
            && $record->parser_key === 'cfisd-grade5-elar-yag-parent' && $record->parser_version === 'cfisd-grade5-elar-yag-parent-v3'));
        $this->assertDatabaseHas('curriculum_units', ['name' => 'Unit 1', 'summary' => 'Reading: reviewed module summary · Writing: reviewed module summary']);
        $this->assertDatabaseHas('curriculum_unit_components', ['name' => 'HMH Module 1: Inventors at Work', 'component_type' => 'module']);
        $this->assertDatabaseHas('curriculum_unit_components', ['name' => 'Sentence Boundaries', 'component_type' => 'revising']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'curriculum-unit.component-imported']);
        $this->actingIn($owner, $tenant)->get(route('academic.curriculum.show', $mapping->curriculumPackage))->assertInertia(fn (Assert $page) => $page
            ->where('package.course_mappings.0.curriculum_periods.0.units.0.components.0.name', 'Reading')
            ->where('package.course_mappings.0.curriculum_periods.0.units.0.summary', 'Reading: reviewed module summary · Writing: reviewed module summary')
            ->where('package.course_mappings.0.curriculum_periods.0.units.0.components.0.descendants.0.name', 'Launching Literacy'));
        $this->actingIn($owner, $tenant)->put(route('academic.curriculum-imports.proposals.bulk-update', $import), ['proposals' => $this->reviewPayload($import)])->assertSessionHasErrors('review');
        $this->actingIn($owner, $tenant)->post(route('academic.curriculum-imports.reextract', $import))->assertSessionHasErrors('reextract');
    }

    public function test_elar_component_approval_rolls_back_every_materialized_record_on_late_failure(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        [$source, $mapping] = $this->curriculumContext($owner, $tenant, 'ELAR');
        $this->extractor->pages = require base_path('tests/Fixtures/cfisd-grade5-elar-yag-positioned.php');
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source))->assertRedirect();
        $import = CurriculumImport::query()->firstOrFail();
        $calls = 0;
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->andReturnUsing(function (string $action) use (&$calls): void {
            if ($action === 'curriculum-unit.component-imported' && ++$calls === 2) throw new \RuntimeException('Simulated component audit failure.');
        });
        $this->app->instance(AuditService::class, $audit);
        $this->actingIn($owner, $tenant);
        try {
            app(CurriculumImportService::class)->approve($import, 0);
            $this->fail('Approval should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated component audit failure.', $exception->getMessage());
        }
        $this->assertDatabaseCount('curriculum_periods', 0);
        $this->assertDatabaseCount('curriculum_units', 0);
        $this->assertDatabaseCount('curriculum_unit_components', 0);
        $this->assertSame('review', $import->fresh()->status);
    }

    public function test_science_import_bulk_review_approval_and_provenance_use_structured_concepts(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        [$source, $mapping] = $this->curriculumContext($owner, $tenant, 'SCI');
        $this->extractor->pages = require base_path('tests/Fixtures/cfisd-grade5-science-yag-positioned.php');
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-capability.store', $source))->assertSessionHasNoErrors();
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), ['curriculum_package_course_id' => $mapping->id])->assertRedirect();
        $import = CurriculumImport::query()->firstOrFail();
        $this->assertSame('cfisd-grade5-science-yag-v1', $import->parser_version);
        $this->assertSame(4, $import->proposals()->where('proposal_type', 'period')->count());
        $this->assertSame(10, $import->proposals()->where('proposal_type', 'unit')->count());
        $this->assertSame(7, $import->proposals()->where('proposal_type', 'assessment')->count());
        $this->assertSame(10, $import->proposals()->where('proposal_type', 'component')->where('component_type', 'concept')->count());
        $payload = $this->reviewPayload($import);
        $this->actingIn($owner, $tenant)->put(route('academic.curriculum-imports.proposals.bulk-update', $import), ['proposals' => $payload])->assertSessionHasNoErrors();
        $version = $import->fresh()->review_version;
        $this->actingIn($owner, $tenant)->post(route('academic.curriculum-imports.approve', $import), ['review_version' => $version])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('curriculum_units', ['name' => 'Ecosystems Unit', 'estimated_days' => 18]);
        $this->assertDatabaseHas('curriculum_unit_standard_alignments', ['standard_code' => '5.12A']);
        $this->assertDatabaseHas('curriculum_unit_components', ['name' => 'Ecosystems Unit', 'component_type' => 'concept', 'parser_key' => 'cfisd-grade5-science-yag']);
        $this->actingIn($owner, $tenant)->put(route('academic.curriculum-imports.proposals.bulk-update', $import), ['proposals' => $this->reviewPayload($import)])->assertSessionHasErrors('review');
    }

    public function test_structured_custom_curriculum_reviews_and_materializes_without_invented_periods_or_dates(): void
    {
        [$owner, $tenant] = $this->tenantUser();
        [$source, $mapping] = $this->curriculumContext($owner, $tenant, 'TECH');
        $this->extractor->pages = require base_path('tests/Fixtures/structured-custom-technology.php');

        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), [
            'curriculum_package_course_id' => $mapping->id,
        ])->assertRedirect();
        $import = CurriculumImport::query()->firstOrFail();
        $this->assertSame('custom-homeschool-curriculum', $import->parser_key);
        $this->assertSame('custom-homeschool-curriculum', $import->document_metadata['document_family']);
        $this->assertSame(8, $import->proposals()->where('proposal_type', 'unit')->count());
        $this->assertSame(0, $import->proposals()->where('proposal_type', 'period')->count());
        $this->assertSame(0, $import->proposals()->where('proposal_type', 'assessment')->count());
        $this->assertNull($import->proposals()->where('proposal_type', 'unit')->firstOrFail()->parent_proposal_id);
        $this->actingIn($owner, $tenant)->get(route('academic.curriculum-imports.show', $import))->assertInertia(fn (Assert $page) => $page
            ->has('periods', 1)
            ->where('periods.0.proposal_type', 'course')
            ->has('periods.0.children', 8));

        $this->actingIn($owner, $tenant)->put(route('academic.curriculum-imports.proposals.bulk-update', $import), [
            'proposals' => $this->reviewPayload($import),
        ])->assertSessionHasNoErrors();
        $this->actingIn($owner, $tenant)->post(route('academic.curriculum-imports.approve', $import), [
            'review_version' => $import->fresh()->review_version,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('curriculum_periods', 0);
        $this->assertSame(8, CurriculumUnit::query()->where('curriculum_package_course_id', $mapping->id)->count());
        $first = CurriculumUnit::query()->where('curriculum_package_course_id', $mapping->id)->orderBy('sequence')->firstOrFail();
        $this->assertNull($first->curriculum_period_id);
        $this->assertNull($first->planned_start_date);
        $this->assertNull($first->planned_end_date);
        $this->assertNull($first->estimated_days);
        $this->assertSame('4 weeks', $first->metadata['duration_text']);
        $this->assertSame('Big idea 1.', $first->summary);
        $this->assertDatabaseHas('curriculum_unit_components', ['curriculum_unit_id' => $first->id, 'component_type' => 'project', 'name' => 'Project 1']);
        $this->assertDatabaseHas('curriculum_unit_components', ['curriculum_unit_id' => $first->id, 'component_type' => 'project_milestone', 'name' => 'Build 1']);
        $this->assertDatabaseHas('curriculum_unit_components', ['curriculum_unit_id' => $first->id, 'component_type' => 'assessment_support', 'name' => 'Evidence of Learning']);
    }

    public function test_custom_duration_is_scheduled_by_the_selected_active_calendar_and_source_dates_are_protected(): void
    {
        $scheduler = app(CurriculumImportSchedulingService::class);
        $this->assertSame(20, $scheduler->instructionalDays('4 weeks', 5));
        $this->assertSame(6, $scheduler->instructionalDays('6 sessions', 5));
        $this->assertSame(12, $scheduler->instructionalDays('12 instructional days', 5));
        $this->assertNull($scheduler->instructionalDays('2-3 weeks', 5));

        [$owner, $tenant] = $this->tenantUser();
        [$source, $mapping] = $this->curriculumContext($owner, $tenant, 'TECH');
        $year = $source->schoolYear;
        $year->update([
            'start_date' => '2026-08-12', 'end_date' => '2027-05-27',
            'instructional_week_type' => 'five_day', 'instructional_weekdays' => [1, 2, 3, 4, 5],
        ]);
        $profile = CalendarProfile::create([
            'education_provider_id' => $source->education_provider_id,
            'name' => 'Approved school calendar', 'academic_year_label' => '2026-2027',
            'start_date' => '2026-08-12', 'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago', 'status' => 'active', 'source_type' => 'provider',
        ]);
        $profile->events()->create([
            'event_date' => '2026-09-07', 'event_type' => 'holiday', 'name' => 'Labor Day',
            'instructional_effect' => 'non_instructional', 'status' => 'active',
        ]);
        $profile->events()->create([
            'event_date' => '2026-10-09', 'event_type' => 'teacher_workday', 'name' => 'Teacher workday',
            'instructional_effect' => 'non_instructional', 'status' => 'active',
        ]);
        AcademicYearConfiguration::create([
            'school_year_id' => $year->id, 'education_provider_id' => $source->education_provider_id,
            'calendar_profile_id' => $profile->id,
            'standards_framework_id' => $mapping->curriculumPackage->standards_framework_id,
            'curriculum_package_id' => $mapping->curriculum_package_id, 'status' => 'draft',
        ]);
        $source->unsetRelation('schoolYear');
        $this->extractor->pages = require base_path('tests/Fixtures/structured-custom-technology.php');

        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), [
            'curriculum_package_course_id' => $mapping->id,
        ])->assertRedirect();
        $import = CurriculumImport::query()->firstOrFail();
        $units = $import->proposals()->where('proposal_type', 'unit')->orderBy('sequence')->get();
        $this->assertCount(8, $units);
        $this->assertSame('4 weeks', $units[0]->parser_metadata['duration_text']);
        $this->assertSame('source', $units[0]->parser_metadata['duration_origin']);
        $this->assertSame('calendar_calculated', $units[0]->parser_metadata['schedule_origin']);
        $this->assertSame('Approved school calendar', $units[0]->parser_metadata['schedule_calendar_name']);
        $this->assertSame('2026-08-12', $units[0]->planned_start_date->format('Y-m-d'));
        $this->assertSame('2026-09-09', $units[0]->planned_end_date->format('Y-m-d'));
        $this->assertSame(20, $units[0]->estimated_days);
        $this->assertSame('2026-09-10', $units[1]->planned_start_date->format('Y-m-d'));
        $this->assertNotSame('2026-10-09', $units[1]->planned_end_date?->format('Y-m-d'));

        $payload = $this->reviewPayload($import);
        $payload[$units[0]->id]['planned_start_date'] = '2026-08-13';
        $this->actingIn($owner, $tenant)->put(route('academic.curriculum-imports.proposals.bulk-update', $import), [
            'proposals' => $payload,
        ])->assertSessionHasNoErrors();
        $this->assertSame('manual_override', $units[0]->fresh()->parser_metadata['schedule_origin']);
        $this->assertSame('calendar_calculated', $units[0]->fresh()->parser_metadata['schedule_previous_origin']);

        $units[0]->update([
            'planned_start_date' => '2026-08-13', 'planned_end_date' => '2026-09-10', 'estimated_days' => 20,
            'parser_metadata' => [...$units[0]->parser_metadata, 'schedule_origin' => 'source'],
        ]);
        app(CurriculumImportSchedulingService::class)->schedule($import->fresh());
        $protected = $units[0]->fresh();
        $this->assertSame('2026-08-13', $protected->planned_start_date->format('Y-m-d'));
        $this->assertSame('2026-09-10', $protected->planned_end_date->format('Y-m-d'));
        $this->assertSame('source', $protected->parser_metadata['schedule_origin']);
    }

    private function start(User $user, Tenant $tenant, AcademicSource $source, CurriculumPackageCourse $mapping): CurriculumImport
    {
        $this->extractor->pages = require base_path('tests/Fixtures/cfisd-grade5-math-yag-positioned.php');
        $this->actingIn($user, $tenant)->post(route('academic.sources.curriculum-imports.store', $source), ['curriculum_package_course_id' => $mapping->id])->assertRedirect();
        return CurriculumImport::query()->firstOrFail();
    }

    private function reviewPayload(CurriculumImport $import): array
    {
        return $import->proposals()->get()->mapWithKeys(fn ($proposal) => [$proposal->id => [
            'id' => $proposal->id, 'parent_proposal_id' => $proposal->parent_proposal_id,
            'included' => $proposal->included, 'sequence' => $proposal->sequence, 'name' => $proposal->name,
            'description' => $proposal->description, 'summary' => $proposal->summary,
            'planned_start_date' => $proposal->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $proposal->planned_end_date?->format('Y-m-d'),
            'estimated_days' => $proposal->estimated_days, 'unit_type' => $proposal->unit_type,
            'component_type' => $proposal->component_type,
            'standard_codes' => $proposal->standard_codes ?? [],
        ]])->all();
    }

    private function curriculumContext(User $owner, Tenant $tenant, string $subjectCode = 'MATH'): array
    {
        $grade = GradeLevel::firstOrCreate(['code' => '5'], ['name' => 'Grade 5', 'sort_order' => 5, 'is_active' => true]);
        $year = $tenant->schoolYears()->create(['name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => 180]);
        $provider = EducationProvider::create(['name' => 'CFISD', 'short_name' => 'CFISD', 'provider_type' => 'public_school_district', 'country_code' => 'US', 'status' => 'active']);
        $framework = StandardsFramework::create(['education_provider_id' => $provider->id, 'name' => 'Texas Essential Knowledge and Skills', 'short_name' => 'TEKS', 'jurisdiction' => 'Texas', 'version_label' => '2026', 'status' => 'active']);
        $subjectName = match ($subjectCode) { 'ELAR' => 'English Language Arts and Reading', 'SCI' => 'Science', 'TECH' => 'Technology', default => 'Mathematics' };
        $subject = Subject::create(['name' => $subjectName, 'code' => $subjectCode, 'sort_order' => 1, 'status' => 'active']);
        $course = Course::create(['subject_id' => $subject->id, 'standards_framework_id' => $framework->id, 'education_provider_id' => $provider->id, 'name' => 'Grade 5 '.$subjectName, 'code' => $subjectCode.'-5', 'minimum_grade_level_id' => $grade->id, 'maximum_grade_level_id' => $grade->id, 'status' => 'draft']);
        $package = CurriculumPackage::create(['education_provider_id' => $provider->id, 'standards_framework_id' => $framework->id, 'name' => 'Grade 5 Curriculum', 'version_label' => '2026-2027', 'status' => 'draft']);
        $mapping = $package->courseMappings()->create(['course_id' => $course->id, 'grade_level_id' => $grade->id, 'sort_order' => 1, 'required' => true]);
        $slug = strtolower($subjectCode);
        $source = AcademicSource::create(['created_by_user_id' => $owner->id, 'education_provider_id' => $provider->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'title' => '5th - '.$subjectCode, 'source_kind' => 'upload', 'source_category' => 'curriculum', 'authority_level' => 'official_provider', 'review_status' => 'reviewed', 'processing_status' => 'unprocessed', 'academic_year_label' => '2026-2027']);
        Storage::disk('local')->put("academic-sources/{$source->id}/{$slug}.pdf", '%PDF-1.4 fixture');
        $source->files()->create(['uploaded_by_user_id' => $owner->id, 'version_number' => 1, 'current_key' => 'current', 'is_current' => true, 'disk' => 'local', 'stored_path' => "academic-sources/{$source->id}/{$slug}.pdf", 'stored_filename' => "{$slug}.pdf", 'original_filename' => "5th - {$subjectCode}.pdf", 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 16, 'checksum_sha256' => str_repeat('a', 64), 'uploaded_at' => now()]);
        $source->links()->create(['link_type' => 'subject', 'link_id' => $subject->id]);
        $source->links()->create(['link_type' => 'standards_framework', 'link_id' => $framework->id]);
        $source->load(['currentFile', 'schoolYear', 'gradeLevel', 'links']);
        $mapping->load(['curriculumPackage', 'course.subject']);
        return [$source, $mapping];
    }

    private function tenantUser(string $role = 'owner', string $name = 'Curriculum Academy'): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => $name, 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);
        app(TenantContext::class)->set($tenant, $membership);
        return [$user, $tenant];
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    }
}
