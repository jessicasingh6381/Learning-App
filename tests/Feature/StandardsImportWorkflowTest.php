<?php

namespace Tests\Feature;

use App\Contracts\PdfTextExtractor;
use App\Models\AcademicSource;
use App\Models\CurriculumImport;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\Standard;
use App\Models\StandardsFramework;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CurriculumParserCapabilityService;
use App\Services\AuditService;
use App\Services\StandardsDocumentMetadataNormalizer;
use App\Services\StandardsImportService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StandardsImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private array $pages;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); Storage::fake('local');
        $this->pages = require base_path('tests/Fixtures/texas-teks-multigrade-social-studies.php');
        $extractor = new class($this->pages) implements PdfTextExtractor {
            public function __construct(public array $pages) {}
            public function extract(string $absolutePath): array { return $this->pages; }
        };
        $this->app->instance(PdfTextExtractor::class, $extractor);
    }

    public function test_source_reassesses_starts_idempotently_bulk_reviews_and_approves_hierarchy(): void
    {
        [$owner, $tenant, $source] = $this->context();
        $capability = app(CurriculumParserCapabilityService::class)->assess($source, true);
        $this->assertSame('supported', $capability->state); $this->assertDatabaseCount('curriculum_imports', 0);
        $this->actingIn($owner, $tenant)->get(route('academic.sources.show', $source))->assertInertia(fn (Assert $page) => $page
            ->where('curriculumSetup.workflow_state', 'standards_ready')
            ->where('curriculumSetup.primary_action_label', 'Import Grade 5 Social Studies standards')
            ->where('curriculumSetup.primary_action_method', 'post'));

        $this->actingIn($owner, $tenant)->post(route('academic.sources.standards-imports.store', $source))->assertRedirect();
        $import = CurriculumImport::query()->firstOrFail();
        $this->assertSame('standards', $import->import_type); $this->assertSame('review', $import->status);
        $this->assertNull($import->curriculum_package_id); $this->assertNull($import->curriculum_package_course_id);
        $this->assertSame('113.16', $import->document_section); $this->assertStringContainsString('In Grade 5', $import->introduction_text);
        $this->assertSame('Adopted 2022', $import->document_metadata['adopted_label']);
        $this->assertSame('August 2024 Update', $import->document_metadata['version_label']);
        $this->assertSame('2024-2025 school year', $import->document_metadata['effective_label']);
        $this->assertStringContainsString('shall be implemented by school districts', $import->document_metadata['implementation_statement']);
        $this->assertSame(8, $import->proposals()->where('proposal_type', 'strand')->count());
        $this->assertSame(8, $import->proposals()->where('proposal_type', 'standard')->count());
        $this->assertSame(10, $import->proposals()->where('proposal_type', 'student_expectation')->count());
        $this->assertDatabaseCount('curriculum_periods', 0); $this->assertDatabaseCount('curriculum_units', 0);
        $this->actingIn($owner, $tenant)->post(route('academic.sources.standards-imports.store', $source))->assertRedirect(route('academic.standards-imports.show', $import));
        $this->assertDatabaseCount('curriculum_imports', 1);
        $this->actingIn($owner, $tenant)->get(route('academic.standards-imports.show', $import))->assertInertia(fn (Assert $page) => $page
            ->component('Academic/StandardsImports/Show')->has('strands', 8)
            ->where('strands.0.children.0.standard_code', '5.1')
            ->where('strands.0.children.0.children.0.standard_code', '5.1A'));

        $invalid = $this->payload($import); $expectations = $import->proposals()->where('proposal_type', 'student_expectation')->take(2)->get();
        $invalid[$expectations[1]->id]['standard_code'] = $expectations[0]->standard_code;
        $before = $expectations[0]->statement;
        $invalid[$expectations[0]->id]['statement'] = 'This must roll back.';
        $this->actingIn($owner, $tenant)->put(route('academic.standards-imports.review.update', $import), ['proposals' => $invalid])->assertSessionHasErrors();
        $this->assertSame($before, $expectations[0]->fresh()->statement); $this->assertSame(0, $import->fresh()->review_version);

        $valid = $this->payload($import); $firstExpectation = $expectations[0];
        $valid[$firstExpectation->id]['statement'] .= ' [reviewed]';
        $this->actingIn($owner, $tenant)->put(route('academic.standards-imports.review.update', $import), ['proposals' => $valid])->assertSessionHasNoErrors();
        $this->assertSame(1, $import->fresh()->review_version); $this->assertTrue($firstExpectation->fresh()->manually_edited);
        $this->actingIn($owner, $tenant)->post(route('academic.standards-imports.approve', $import), ['review_version' => 1])->assertSessionHasNoErrors();
        $import->refresh(); $this->assertSame('approved', $import->status);
        $this->assertDatabaseCount('standards', 26); $this->assertDatabaseCount('curriculum_periods', 0); $this->assertDatabaseCount('curriculum_units', 0);
        $parent = Standard::query()->where('standard_code', '5.1')->firstOrFail();
        $child = Standard::query()->where('standard_code', '5.1A')->firstOrFail();
        $this->assertSame('Adopted 2022', $child->adopted_label);
        $this->assertSame('August 2024 Update', $child->version_label);
        $this->assertSame('2024-2025 school year', $child->effective_label);
        $this->assertStringNotContainsString('shall be implemented', $child->effective_label);
        $this->assertSame($parent->id, $child->parent_standard_id); $this->assertStringEndsWith('[reviewed]', $child->statement);
        $this->assertSame($source->currentFile->id, $child->academic_source_file_id); $this->assertSame($firstExpectation->source_page, $child->source_page);
        $this->actingIn($owner, $tenant)->put(route('academic.standards-imports.review.update', $import), ['proposals' => $valid])->assertSessionHasErrors();
        $this->assertDatabaseCount('standards', 26);
        $this->actingIn($owner, $tenant)->get(route('academic.sources.show', $source))->assertInertia(fn (Assert $page) => $page
            ->where('curriculumSetup.workflow_state', 'standards_imported'));
    }

    public function test_standards_routes_are_tenant_safe_and_students_are_denied(): void
    {
        [$owner, $tenant, $source] = $this->context(); app(CurriculumParserCapabilityService::class)->assess($source, true);
        $student = User::factory()->create(); TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $student->id, 'role' => 'student', 'status' => 'active']);
        $this->actingIn($student, $tenant)->post(route('academic.sources.standards-imports.store', $source))->assertForbidden();
        $this->assertDatabaseCount('curriculum_imports', 0);
        $this->actingIn($owner, $tenant)->post(route('academic.sources.standards-imports.store', $source)); $import = CurriculumImport::firstOrFail();
        [$foreign, $foreignTenant] = $this->tenantUser('Foreign');
        $this->actingIn($foreign, $foreignTenant)->get('/academic-setup/standards-imports/'.$import->id)->assertNotFound();
        $this->actingIn($foreign, $foreignTenant)->post('/academic-setup/standards-imports/'.$import->id.'/approve', ['review_version' => 0])->assertNotFound();
    }

    public function test_approval_rolls_back_every_standard_when_a_late_audit_fails(): void
    {
        [$owner, $tenant, $source] = $this->context(); app(CurriculumParserCapabilityService::class)->assess($source, true);
        $this->actingIn($owner, $tenant)->post(route('academic.sources.standards-imports.store', $source));
        $import = CurriculumImport::firstOrFail(); $calls = 0;
        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->andReturnUsing(function (string $action) use (&$calls): void {
            if ($action === 'standard.imported' && ++$calls === 4) throw new \RuntimeException('late standards audit failure');
        });
        $service = new StandardsImportService(app(CurriculumParserCapabilityService::class), $audit, app(StandardsDocumentMetadataNormalizer::class));
        try { $service->approve($import, 0); $this->fail('Approval should have failed.'); }
        catch (\RuntimeException $exception) { $this->assertSame('late standards audit failure', $exception->getMessage()); }
        $this->assertDatabaseCount('standards', 0); $this->assertSame('review', $import->fresh()->status);
        $this->assertNull($import->fresh()->approved_at); $this->assertDatabaseCount('curriculum_periods', 0); $this->assertDatabaseCount('curriculum_units', 0);
    }

    public function test_overlong_materialized_value_is_rejected_before_insert_and_review_is_preserved(): void
    {
        [$owner, $tenant, $source] = $this->context(); app(CurriculumParserCapabilityService::class)->assess($source, true);
        $this->actingIn($owner, $tenant)->post(route('academic.sources.standards-imports.store', $source));
        $import = CurriculumImport::firstOrFail();
        $proposal = $import->proposals()->where('proposal_type', 'standard')->firstOrFail();
        $proposal->update(['normalized_code' => str_repeat('X', 101), 'statement' => 'Saved review wording.']);

        $this->actingIn($owner, $tenant)->post(route('academic.standards-imports.approve', $import), ['review_version' => 0])
            ->assertSessionHasErrors(['approval', "proposals.{$proposal->id}.normalized_code"]);

        $this->assertDatabaseCount('standards', 0);
        $this->assertSame('review', $import->fresh()->status);
        $this->assertSame('Saved review wording.', $proposal->fresh()->statement);
        $this->assertNull($import->fresh()->approved_at);
    }

    public function test_database_failure_returns_safe_approval_error_and_rolls_back(): void
    {
        [$owner, $tenant, $source] = $this->context(); app(CurriculumParserCapabilityService::class)->assess($source, true);
        $this->actingIn($owner, $tenant)->post(route('academic.sources.standards-imports.store', $source));
        $import = CurriculumImport::firstOrFail();
        DB::unprepared("CREATE TRIGGER standards_force_failure BEFORE INSERT ON standards BEGIN SELECT RAISE(ABORT, 'raw SQL detail'); END");
        try {
            $response = $this->actingIn($owner, $tenant)->post(route('academic.standards-imports.approve', $import), ['review_version' => 0]);
            $response->assertSessionHasErrors(['approval']);
            $this->assertSame(
                'Approval could not be completed. No standards were imported; review the saved values and try again.',
                session('errors')->get('approval')[0],
            );
            $this->assertStringNotContainsString('raw SQL detail', session('errors')->get('approval')[0]);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS standards_force_failure');
        }
        $this->assertDatabaseCount('standards', 0);
        $this->assertSame('review', $import->fresh()->status);
        $this->assertNull($import->fresh()->approved_at);
    }

    private function payload(CurriculumImport $import): array
    {
        return $import->proposals()->get()->mapWithKeys(fn ($row) => [$row->id => [
            'id' => $row->id, 'included' => $row->included, 'sequence' => $row->sequence,
            'standard_code' => $row->standard_code, 'statement' => $row->statement,
        ]])->all();
    }

    private function context(): array
    {
        [$owner, $tenant] = $this->tenantUser('Standards Academy');
        $grade = GradeLevel::create(['name' => 'Grade 5', 'code' => 'G5', 'sort_order' => 5, 'is_active' => true]);
        $year = $tenant->schoolYears()->create(['name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => 180]);
        $provider = EducationProvider::create(['name' => 'CFISD', 'short_name' => 'CFISD', 'provider_type' => 'public_school_district', 'country_code' => 'US', 'status' => 'active']);
        $framework = StandardsFramework::create(['education_provider_id' => $provider->id, 'name' => 'Texas Essential Knowledge and Skills', 'short_name' => 'TEKS', 'jurisdiction' => 'Texas', 'version_label' => '2022', 'status' => 'active']);
        $subject = Subject::create(['name' => 'Social Studies', 'code' => 'SS', 'sort_order' => 1, 'status' => 'active']);
        $source = AcademicSource::create(['created_by_user_id' => $owner->id, 'education_provider_id' => $provider->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'title' => '5th - SS', 'source_kind' => 'upload', 'source_category' => 'curriculum', 'authority_level' => 'official_provider', 'review_status' => 'reviewed', 'processing_status' => 'not_requested']);
        Storage::disk('local')->put("academic-sources/{$source->id}/ss.pdf", '%PDF fixture');
        $source->files()->create(['uploaded_by_user_id' => $owner->id, 'version_number' => 1, 'current_key' => 'current', 'is_current' => true, 'disk' => 'local', 'stored_path' => "academic-sources/{$source->id}/ss.pdf", 'stored_filename' => 'ss.pdf', 'original_filename' => 'Social Studies.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 12, 'checksum_sha256' => str_repeat('e', 64), 'uploaded_at' => now()]);
        $source->links()->create(['link_type' => 'subject', 'link_id' => $subject->id]); $source->links()->create(['link_type' => 'standards_framework', 'link_id' => $framework->id]);
        return [$owner, $tenant, $source->load(['currentFile', 'gradeLevel', 'links'])];
    }

    private function tenantUser(string $name): array
    {
        $user = User::factory()->create(); $tenant = Tenant::create(['name' => $name, 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']); app(TenantContext::class)->set($tenant, $membership);
        return [$user, $tenant];
    }
    private function actingIn(User $user, Tenant $tenant): static { return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]); }
}
