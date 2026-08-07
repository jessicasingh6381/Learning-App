<?php

namespace Tests\Feature;

use App\Contracts\CurriculumOutlineParser;
use App\Contracts\PdfTextExtractor;
use App\Data\CurriculumParserApplicability;
use App\Data\CurriculumParserResult;
use App\Models\AcademicSource;
use App\Models\Course;
use App\Models\CurriculumImport;
use App\Models\CurriculumPackage;
use App\Models\CurriculumPackageCourse;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CfisdGrade5MathYearAtGlanceParser;
use App\Services\CfisdGrade5ElarParentYearAtGlanceParser;
use App\Services\CurriculumParserCapabilityService;
use App\Services\CurriculumParserRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CurriculumParserCapabilityTest extends TestCase
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
            public int $calls = 0;
            public function extract(string $absolutePath): array { $this->calls++; return $this->pages; }
        };
        $this->app->instance(PdfTextExtractor::class, $this->extractor);
    }

    public function test_registry_is_iterable_deterministic_prioritized_ambiguous_and_signature_versioned(): void
    {
        [$owner, $tenant, $source] = $this->context();
        $pages = require base_path('tests/Fixtures/cfisd-grade5-math-yag-positioned.php');
        $low = $this->fakeParser('low', 'v1', 10, .9);
        $high = $this->fakeParser('high', 'v1', 20, .8);
        $registry = new CurriculumParserRegistry([$low, $high]);
        $this->assertCount(2, iterator_to_array($registry));
        $result = $registry->assess($pages, $source, $source->currentFile);
        $this->assertSame('supported', $result->state);
        $this->assertSame('high', $result->parserKey);
        $this->assertSame($registry->signature(), (new CurriculumParserRegistry([$high, $low]))->signature());
        $this->assertNotSame($registry->signature(), (new CurriculumParserRegistry([$low, $this->fakeParser('high', 'v2', 20, .8)]))->signature());

        $ambiguous = (new CurriculumParserRegistry([$high, $this->fakeParser('other', 'v1', 20, .7)]))
            ->assess($pages, $source, $source->currentFile);
        $this->assertSame('ambiguous', $ambiguous->state);
        $this->assertCount(2, $ambiguous->candidateParsers);
    }

    public function test_math_parser_requires_matching_subject_grade_provider_text_and_positioned_layout(): void
    {
        [$owner, $tenant, $source] = $this->context();
        $parser = app(CfisdGrade5MathYearAtGlanceParser::class);
        $pages = require base_path('tests/Fixtures/cfisd-grade5-math-yag-positioned.php');
        $this->assertTrue($parser->supports($pages, $source));
        $this->assertSame(.99, $parser->recognitionScore($pages, $source));
        $withoutLayout = $pages; $withoutLayout[0]['items'] = [];
        $this->assertFalse($parser->supports($withoutLayout, $source));

        $elar = Subject::create(['name' => 'English Language Arts and Reading', 'code' => 'ELAR', 'sort_order' => 2, 'status' => 'active']);
        $source->links()->where('link_type', 'subject')->update(['link_id' => $elar->id]); $source->unsetRelation('links');
        $this->assertFalse($parser->supports($pages, $source));
        $source->links()->where('link_type', 'subject')->update(['link_id' => Subject::query()->where('code', 'MATH')->value('id')]); $source->unsetRelation('links');
        $source->gradeLevel->update(['code' => 'G6', 'name' => 'Grade 6']); $source->unsetRelation('gradeLevel');
        $this->assertFalse($parser->supports($pages, $source));
        $source->gradeLevel->update(['code' => 'G5', 'name' => 'Grade 5']); $source->unsetRelation('gradeLevel');
        $source->educationProvider->update(['short_name' => 'OTHER', 'name' => 'Other Provider']); $source->unsetRelation('educationProvider');
        $this->assertFalse($parser->supports($pages, $source));
    }

    public function test_unsupported_elar_assessment_is_cached_non_mutating_and_invalidated_by_checksum_and_registry(): void
    {
        [$owner, $tenant, $source] = $this->context('ELAR');
        $this->extractor->pages = require base_path('tests/Fixtures/cfisd-grade5-elar-yag-positioned.php');
        $oldRegistry = new CurriculumParserRegistry([app(CfisdGrade5MathYearAtGlanceParser::class)]);
        $this->app->instance(CurriculumParserRegistry::class, $oldRegistry);
        $service = app(CurriculumParserCapabilityService::class);
        $beforeStatus = $source->processing_status;
        $first = $service->assess($source);
        $this->assertSame('unsupported', $first->state);
        $this->assertSame(0, $this->extractor->calls, 'Metadata applicability should reject ELAR before PDF extraction.');
        $this->assertSame('unsupported', $service->assess($source)->state);
        $this->actingIn($owner, $tenant)->get(route('academic.sources.show', $source))->assertInertia(fn (Assert $page) => $page
            ->where('curriculumSetup.workflow_state', 'format_setup_needed')
            ->where('curriculumSetup.primary_action_label', 'Set up this document format'));
        $this->assertDatabaseCount('curriculum_parser_capabilities', 1);
        $this->assertDatabaseCount('curriculum_imports', 0);
        $this->assertDatabaseCount('curriculum_packages', 0);
        $this->assertDatabaseCount('courses', 0);
        $this->assertDatabaseCount('curriculum_package_courses', 0);
        $this->assertDatabaseCount('curriculum_import_proposals', 0);
        $this->assertSame($beforeStatus, $source->fresh()->processing_status);
        $this->assertFalse($source->links()->where('link_type', 'curriculum_package')->exists());

        $source->currentFile->update(['checksum_sha256' => str_repeat('b', 64)]); $source->unsetRelation('currentFile');
        $this->assertSame('unknown', $service->cached($source)->state);
        $this->assertSame('unsupported', $service->assess($source)->state);

        $this->app->instance(CurriculumParserRegistry::class, new CurriculumParserRegistry([
            app(CfisdGrade5MathYearAtGlanceParser::class), app(CfisdGrade5ElarParentYearAtGlanceParser::class),
        ]));
        $this->actingIn($owner, $tenant)->get(route('academic.sources.show', $source))->assertInertia(fn (Assert $page) => $page
            ->where('curriculumSetup.workflow_state', 'unknown')->where('curriculumSetup.primary_action_label', 'Check outline support'));
        $newService = app(CurriculumParserCapabilityService::class);
        $this->assertSame('unknown', $newService->cached($source)->state);
        $this->assertSame('supported', $newService->assess($source)->state);
        $this->assertSame(CfisdGrade5ElarParentYearAtGlanceParser::KEY, $newService->cached($source)->parserKey);
        $this->actingIn($owner, $tenant)->get(route('academic.sources.show', $source))->assertInertia(fn (Assert $page) => $page
            ->where('curriculumSetup.workflow_state', 'ready')->where('curriculumSetup.primary_action_label', 'Create curriculum outline'));
        $this->assertSame(1, $source->fresh()->files()->count());
        $this->assertSame(1, $this->extractor->calls);
    }

    public function test_no_text_layer_is_safe_and_assessment_authorization_and_tenant_scope_are_enforced(): void
    {
        [$owner, $tenant, $source] = $this->context();
        $this->extractor->pages = [['page' => 1, 'text' => '', 'items' => []]];
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-capability.store', $source))->assertRedirect();
        $this->assertDatabaseHas('curriculum_parser_capabilities', ['academic_source_id' => $source->id, 'state' => 'failed']);
        $this->assertSame('not_requested', $source->fresh()->processing_status);
        $this->assertDatabaseCount('curriculum_imports', 0);

        $source->update(['review_status' => 'unreviewed']);
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-capability.store', $source))->assertSessionHasErrors('source');
        $source->update(['review_status' => 'reviewed', 'archived_at' => now()]);
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-capability.store', $source))->assertSessionHasErrors('source');

        $student = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $student->id, 'role' => 'student', 'status' => 'active']);
        $this->actingIn($student, $tenant)->post(route('academic.sources.curriculum-capability.store', $source))->assertForbidden();
        [$otherOwner, $otherTenant] = $this->tenantUser('Other Tenant');
        $this->actingIn($otherOwner, $otherTenant)->post('/academic-setup/sources/'.$source->id.'/curriculum-capability')->assertNotFound();
    }

    public function test_ambiguous_or_newly_unsupported_content_cannot_create_setup_or_import_records(): void
    {
        [$owner, $tenant, $source] = $this->context();
        $pages = require base_path('tests/Fixtures/cfisd-grade5-math-yag-positioned.php');
        $this->extractor->pages = $pages;
        $this->app->instance(CurriculumParserRegistry::class, new CurriculumParserRegistry([
            $this->fakeParser('one', 'v1', 100, .9), $this->fakeParser('two', 'v1', 100, .8),
        ]));
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source))->assertSessionHasErrors('source');
        $this->assertNoCurriculumSetup();

        $this->app->instance(CurriculumParserRegistry::class, new CurriculumParserRegistry([app(CfisdGrade5MathYearAtGlanceParser::class)]));
        $this->extractor->pages = $pages;
        app(CurriculumParserCapabilityService::class)->assess($source, true);
        $this->extractor->pages = require base_path('tests/Fixtures/cfisd-grade5-elar-yag-positioned.php');
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-imports.store', $source))->assertSessionHasErrors('source');
        $this->assertNoCurriculumSetup();
    }

    private function assertNoCurriculumSetup(): void
    {
        $this->assertDatabaseCount('curriculum_imports', 0);
        $this->assertDatabaseCount('curriculum_packages', 0);
        $this->assertDatabaseCount('courses', 0);
        $this->assertDatabaseCount('curriculum_package_courses', 0);
    }

    private function fakeParser(string $key, string $version, int $priority, float $score, string $subject = 'MATH'): CurriculumOutlineParser
    {
        return new class($key, $version, $priority, $score, $subject) implements CurriculumOutlineParser {
            public function __construct(private string $parserKey, private string $parserVersion, private int $priority, private float $score, private string $subject) {}
            public function supports(array $pages, AcademicSource $source): bool { return $this->score > 0; }
            public function recognitionScore(array $pages, AcademicSource $source): float { return $this->score; }
            public function applicability(): CurriculumParserApplicability { return new CurriculumParserApplicability(['CFISD'], [$this->subject], ['G5', 'Grade 5'], ['curriculum'], ['application/pdf'], ['pdf'], 'Test YAG', $this->priority); }
            public function parse(array $pages, AcademicSource $source): CurriculumParserResult { return new CurriculumParserResult('Test', null, null, null, null, []); }
            public function key(): string { return $this->parserKey; }
            public function version(): string { return $this->parserVersion; }
            public function extractionMethod(): string { return 'pdf_positioned_text'; }
        };
    }

    private function context(string $subjectCode = 'MATH'): array
    {
        [$owner, $tenant] = $this->tenantUser();
        $grade = GradeLevel::create(['code' => 'G5', 'name' => 'Grade 5', 'sort_order' => 5, 'is_active' => true]);
        $year = $tenant->schoolYears()->create(['name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => 180]);
        $provider = EducationProvider::create(['name' => 'Cypress-Fairbanks Independent School District', 'short_name' => 'CFISD', 'provider_type' => 'public_school_district', 'country_code' => 'US', 'status' => 'active']);
        $subject = Subject::create(['name' => $subjectCode === 'MATH' ? 'Mathematics' : 'English Language Arts and Reading', 'code' => $subjectCode, 'sort_order' => 1, 'status' => 'active']);
        $source = AcademicSource::create(['created_by_user_id' => $owner->id, 'education_provider_id' => $provider->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'title' => 'Grade 5 '.$subject->name, 'source_kind' => 'upload', 'source_category' => 'curriculum', 'authority_level' => 'official_provider', 'review_status' => 'reviewed', 'processing_status' => 'not_requested']);
        Storage::disk('local')->put("academic-sources/{$source->id}/source.pdf", '%PDF fixture');
        $source->files()->create(['uploaded_by_user_id' => $owner->id, 'version_number' => 1, 'current_key' => 'current', 'is_current' => true, 'disk' => 'local', 'stored_path' => "academic-sources/{$source->id}/source.pdf", 'stored_filename' => 'source.pdf', 'original_filename' => 'source.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 12, 'checksum_sha256' => str_repeat('a', 64), 'uploaded_at' => now()]);
        $source->links()->create(['link_type' => 'subject', 'link_id' => $subject->id]);
        return [$owner, $tenant, $source->load(['currentFile', 'educationProvider', 'gradeLevel', 'links'])];
    }

    private function tenantUser(string $name = 'Capability Tenant'): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => $name, 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        app(TenantContext::class)->set($tenant, $membership);
        return [$user, $tenant];
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        $membership = TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);
        return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    }
}
