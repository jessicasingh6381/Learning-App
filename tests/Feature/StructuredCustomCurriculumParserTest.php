<?php

namespace Tests\Feature;

use App\Contracts\PdfTextExtractor;
use App\Models\AcademicSource;
use App\Models\CurriculumFormatProfile;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CurriculumDocumentStructureDetector;
use App\Services\CurriculumParserRegistry;
use App\Services\CurriculumParserCapabilityService;
use App\Services\StructuredCustomCurriculumParser;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StructuredCustomCurriculumParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_technology_structure_is_recognized_without_course_level_false_positives(): void
    {
        $source = $this->source();
        $pages = require base_path('tests/Fixtures/structured-custom-technology.php');
        $parser = app(StructuredCustomCurriculumParser::class);
        $this->assertSame(.985, $parser->recognitionScore($pages, $source));

        $result = $parser->parse($pages, $source);
        $rows = collect($result->proposals);
        $units = $rows->where('proposalType', 'unit')->values();
        $this->assertCount(8, $units);
        $this->assertSame('Unit 1 - Mission Control: Make the Computer Do Stuff', $units->first()->name);
        $this->assertSame('Unit 8 - Capstone: Junior Aerospace Software Engineer', $units->last()->name);
        $this->assertFalse($units->contains(fn ($unit) => str_contains($unit->name, 'Course Unit Map') || str_contains($unit->name, '40%') || str_contains($unit->name, 'Requirements')));
        $this->assertCount(0, $rows->where('proposalType', 'period'));
        $this->assertCount(0, $rows->where('proposalType', 'assessment'));
        $this->assertTrue($units->every(fn ($unit) => $unit->plannedStartDate === null && $unit->plannedEndDate === null && $unit->estimatedDays === null));
        $this->assertSame('4 weeks', $units->first()->parserMetadata['duration_text']);
        $this->assertSame('Big idea 1.', $units->first()->summary);
        $this->assertSame(8, $rows->where('componentType', 'project')->count());
        $this->assertSame(8, $rows->where('componentType', 'project_milestone')->count());
        $this->assertSame(8, $rows->where('componentType', 'extension')->count());
        $this->assertSame(16, $rows->where('componentType', 'assessment_support')->count());
        $this->assertTrue($rows->where('componentType', 'project_milestone')->every(fn ($row) => str_starts_with($row->parentKey, 'unit:') && str_ends_with($row->parentKey, ':project')));
        $this->assertSame('custom-homeschool-curriculum', $result->metadata['document_family']);
        $this->assertFalse($result->metadata['reporting_periods_supplied']);
        $this->assertFalse($result->metadata['dates_supplied']);
        $this->assertEquals($result, $parser->parse($pages, $source), 'Recognition and proposal order must be deterministic.');
    }

    public function test_detector_surfaces_only_explicit_units_and_intentional_unit_evidence(): void
    {
        $source = $this->source();
        $detected = app(CurriculumDocumentStructureDetector::class)
            ->detect(require base_path('tests/Fixtures/structured-custom-technology.php'), $source);
        $this->assertCount(8, $detected['unit_rows']);
        $this->assertNotContains('Course Unit Map', $detected['unit_rows']);
        $this->assertNotContains('40% - Unit Projects', $detected['unit_rows']);
        $this->assertNotContains('Final Unit Project Requirements', $detected['unit_rows']);
        $this->assertNotContains('Assessment Philosophy', $detected['assessment_rows']);
        $this->assertNotContains('Evidence of Learning', $detected['assessment_rows']);
        $this->assertSame([], $detected['unit_ambiguities']);
    }

    public function test_spanish_course_map_prose_is_rejected_and_unit_sections_remain_scoped(): void
    {
        $source = $this->source();
        $spanish = Subject::create(['name' => 'Spanish', 'code' => 'SPAN', 'sort_order' => 2, 'status' => 'active']);
        $source->links()->where('link_type', 'subject')->update(['link_id' => $spanish->id]);
        $source->unsetRelation('links');
        $pages = require base_path('tests/Fixtures/structured-custom-spanish.php');
        $parser = app(StructuredCustomCurriculumParser::class);

        $this->assertSame(.985, $parser->recognitionScore($pages, $source));
        $result = $parser->parse($pages, $source);
        $rows = collect($result->proposals);
        $units = $rows->where('proposalType', 'unit')->values();
        $expected = [
            'Unit 1 - Hola, Soy Yo',
            'Unit 2 - Números, Colores y Mi Día',
            'Unit 3 - Mi Familia y Las Personas',
            'Unit 4 - Mi Escuela Ideal',
            'Unit 5 - Tengo Hambre',
            'Unit 6 - Mi Mundo',
            'Unit 7 - Vamos de Viaje',
            'Unit 8 - Mi Aventura en Español',
        ];
        $this->assertSame($expected, $units->pluck('name')->all());
        $this->assertSame(range(1, 8), $units->pluck('parserMetadata.unit_number')->all());
        $this->assertSame($expected, $units->pluck('rawText')->all());
        $this->assertFalse($units->contains(fn ($unit) => str_contains($unit->name, 'I can communicate')));
        $this->assertCount(8, $rows->where('componentType', 'assessment_support')->where('name', 'Evidence of Learning'));
        $this->assertCount(8, $rows->where('componentType', 'resource')->where('name', 'Vocabulary'));
        $this->assertCount(8, $rows->where('componentType', 'resource')->where('name', 'Useful Phrases'));
        $this->assertCount(8, $rows->where('componentType', 'project'));
        $this->assertCount(8, $rows->where('componentType', 'project_milestone'));
        $this->assertCount(8, $rows->where('componentType', 'extension'));
        $this->assertCount(8, $rows->where('componentType', 'duration'));
        $this->assertTrue($rows->whereNotNull('componentType')->every(fn ($row) => preg_match('/^unit:[1-8](?::|$)/', $row->parentKey)));
        $this->assertCount(0, $rows->where('proposalType', 'assessment'));
        $this->assertCount(0, $rows->where('proposalType', 'period'));
        $this->assertTrue($units->every(fn ($unit) => $unit->plannedStartDate === null && $unit->plannedEndDate === null && $unit->estimatedDays === null));
        $this->assertSame('Assessment Philosophy', $result->metadata['course_metadata']['assessment_policy_heading']);

        $detected = app(CurriculumDocumentStructureDetector::class)->detect($pages, $source);
        $this->assertSame($expected, $detected['unit_rows']);
        $this->assertSame([], $detected['unit_ambiguities']);
        $this->assertNotContains('Evidence of Learning', $detected['assessment_rows']);
    }

    public function test_social_studies_pacing_detects_all_units_and_keeps_pacing_sections_unit_local(): void
    {
        $source = $this->source();
        $socialStudies = Subject::create(['name' => 'Social Studies', 'code' => 'SS', 'sort_order' => 2, 'status' => 'active']);
        $source->links()->where('link_type', 'subject')->update(['link_id' => $socialStudies->id]);
        $source->unsetRelation('links');
        $pages = require base_path('tests/Fixtures/structured-custom-social-studies.php');
        $parser = app(StructuredCustomCurriculumParser::class);
        $result = $parser->parse($pages, $source);
        $rows = collect($result->proposals);
        $units = $rows->where('proposalType', 'unit')->values();
        $expected = [
            'Unit 1 - Foundations: Reading the United States',
            'Unit 2 - Colonization and Early America',
            'Unit 3 - Revolution, Independence, and the Constitution',
            'Unit 4 - A Growing Nation: War of 1812, Industry, and Expansion',
            'Unit 5 - Sectionalism, Civil War, Reconstruction, and the West',
            'Unit 6 - Industrial America and Immigration',
            'Unit 7 - The United States in Crisis and Change',
            'Unit 8 - America in the 21st Century',
            'Unit 9 - U.S. Geography and Economy Synthesis',
            'Unit 10 - Government, Citizenship, Culture, and American Identity',
            'Unit 11 - America Through Time - Social Studies Capstone',
        ];

        $this->assertSame(.985, $parser->recognitionScore($pages, $source));
        $this->assertSame($expected, $units->pluck('name')->all());
        $this->assertSame(range(1, 11), $units->pluck('parserMetadata.unit_number')->all());
        $this->assertSame($expected[8], $units[8]->rawText);
        $this->assertCount(0, $rows->where('proposalType', 'period'));
        $this->assertCount(0, $rows->where('proposalType', 'assessment'));
        $this->assertCount(11, $rows->where('componentType', 'concept')->where('name', 'Key Content'));
        $this->assertCount(11, $rows->where('componentType', 'skill')->where('name', 'Social Studies Skills to Practice'));
        $this->assertCount(11, $rows->where('componentType', 'assessment_support')->where('name', 'End-of-Unit Evidence'));
        $this->assertSame('Aug 24-Sep 4; Sep 8-11 (instructional days only)', $units[1]->parserMetadata['instruction_window_text']);
        $this->assertSame(['Aug 24-Sep 4', 'Sep 8-11'], $units[1]->parserMetadata['instruction_windows']);
        $this->assertSame(['Mar 22-25', 'Mar 29-Apr 23'], $units[8]->parserMetadata['instruction_windows']);
        $this->assertSame('5.6, 5.7, 5.8, 5.9, 5.10, 5.11, 5.12, 5.24', $units[8]->parserMetadata['primary_teks_text']);
        $this->assertSame(['5.6', '5.7', '5.8', '5.9', '5.10', '5.11', '5.12', '5.24'], $units[8]->standardCodes);
        $this->assertTrue($units->every(fn ($unit) => $unit->plannedStartDate === null && $unit->plannedEndDate === null && $unit->estimatedDays === null));
        $this->assertFalse($rows->contains(fn ($row) => str_contains($row->name, 'Scheduling Rules') || str_contains($row->name, 'Implementation Notes')));

        $detected = app(CurriculumDocumentStructureDetector::class)->detect($pages, $source);
        $this->assertSame([], $detected['headings']);
        $this->assertSame($expected, $detected['unit_rows']);
        $this->assertSame([], $detected['unit_ambiguities']);
        $this->assertSame([], $detected['assessment_rows']);
        $this->assertNotContains('2026-2027 Pacing Guide', $detected['headings']);
        $this->assertFalse(collect($detected['headings'])->contains(fn ($heading) => str_contains($heading, 'guide is intentionally structured')));
    }

    public function test_missing_explicit_unit_number_fails_safely_instead_of_importing_an_incomplete_outline(): void
    {
        $source = $this->source();
        $pages = require base_path('tests/Fixtures/structured-custom-social-studies.php');
        $pages = collect($pages)->reject(fn ($page) => str_contains($page['text'], 'Unit 9 -'))->values()->all();
        $parser = app(StructuredCustomCurriculumParser::class);
        $detected = app(CurriculumDocumentStructureDetector::class)->detect($pages, $source);

        $this->assertSame(.72, $parser->recognitionScore($pages, $source));
        $this->assertSame('missing_unit_numbers', $detected['unit_ambiguities'][0]['type']);
        $this->assertSame([9], $detected['unit_ambiguities'][0]['numbers']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('needs review');
        $parser->parse($pages, $source);
    }

    public function test_equal_duplicate_unit_headings_are_reported_as_ambiguous_instead_of_duplicated(): void
    {
        $source = $this->source();
        $pages = [[
            'page' => 1,
            'text' => "Unit 1 - Alpha\nBig Idea: One\nLearning Goals\n• Goal\nUnit 1 - Bravo\nBig Idea: Two\nLearning Goals\n• Goal\nUnit 2 - Second\nBig Idea: Three\nLearning Goals\n• Goal",
            'items' => [],
        ]];
        $parser = app(StructuredCustomCurriculumParser::class);
        $this->assertSame(.72, $parser->recognitionScore($pages, $source));
        $capability = (new CurriculumParserRegistry([$parser]))->assess($pages, $source, $source->currentFile);
        $this->assertSame('ambiguous', $capability->state);
        $detected = app(CurriculumDocumentStructureDetector::class)->detect($pages, $source);
        $this->assertCount(1, $detected['unit_ambiguities']);
        $this->assertSame(1, $detected['unit_ambiguities'][0]['number']);
        $this->assertNotContains('Unit 1 - Alpha', $detected['unit_rows']);
        $this->assertNotContains('Unit 1 - Bravo', $detected['unit_rows']);
    }

    public function test_supported_family_reassessment_supersedes_a_noisy_draft_profile_without_creating_an_import(): void
    {
        $source = $this->source();
        $spanish = Subject::create(['name' => 'Spanish', 'code' => 'SPAN', 'sort_order' => 2, 'status' => 'active']);
        $source->links()->where('link_type', 'subject')->update(['link_id' => $spanish->id]);
        $source = $source->fresh(['currentFile', 'educationProvider', 'gradeLevel', 'schoolYear', 'links']);
        $pages = require base_path('tests/Fixtures/structured-custom-spanish.php');
        $extractor = new class($pages) implements PdfTextExtractor {
            public function __construct(private array $pages) {}
            public function extract(string $absolutePath): array { return $this->pages; }
        };
        $this->app->instance(PdfTextExtractor::class, $extractor);
        $profile = CurriculumFormatProfile::create([
            'ownership_scope' => 'tenant', 'education_provider_id' => $source->education_provider_id,
            'subject_id' => $spanish->id, 'minimum_grade_level_id' => $source->grade_level_id,
            'maximum_grade_level_id' => $source->grade_level_id, 'example_academic_source_id' => $source->id,
            'example_academic_source_file_id' => $source->currentFile->id, 'name' => 'Noisy Spanish format',
            'document_family' => 'Unclassified curriculum document', 'file_type' => 'application/pdf',
            'recognition_fingerprints' => ['page_count' => 8],
            'mapping_rules' => ['strategy' => 'confirmed_heading_rows'],
            'detected_structure' => ['unit_rows' => ['Unit 1: Hola, Soy Yo I can communicate.', 'Unit 1 - Hola, Soy Yo'], 'assessment_rows' => ['Evidence of Learning']],
            'profile_version' => 1, 'status' => 'draft', 'created_by_user_id' => $source->created_by_user_id,
        ]);

        $capability = app(CurriculumParserCapabilityService::class)->assess($source, true);
        $this->assertSame('supported', $capability->state);
        $this->assertSame(StructuredCustomCurriculumParser::KEY, $capability->parserKey);
        $this->assertSame(StructuredCustomCurriculumParser::VERSION, $capability->parserVersion);
        $profile->refresh();
        $this->assertSame('superseded', $profile->status);
        $this->assertSame(StructuredCustomCurriculumParser::FAMILY, $profile->document_family);
        $this->assertCount(8, $profile->detected_structure['unit_rows']);
        $this->assertSame([], $profile->detected_structure['assessment_rows']);
        $this->assertSame([], $profile->mapping_rules['confirmed_period_headings']);
        $this->assertCount(8, $profile->mapping_rules['confirmed_unit_rows']);
        $this->assertSame([], $profile->mapping_rules['confirmed_assessment_rows']);
        $this->assertSame(StructuredCustomCurriculumParser::KEY, $profile->mapping_rules['superseded_by_parser']);
        $this->assertSame(StructuredCustomCurriculumParser::VERSION, $profile->mapping_rules['superseded_by_version']);
        $this->assertDatabaseCount('curriculum_imports', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'curriculum-format-profile.superseded-by-parser']);
    }

    public function test_family_reuses_across_subjects_and_supports_spanish_section_vocabulary(): void
    {
        $source = $this->source();
        $spanish = Subject::create(['name' => 'Spanish', 'code' => 'SPAN', 'sort_order' => 2, 'status' => 'active']);
        $source->links()->where('link_type', 'subject')->update(['link_id' => $spanish->id]);
        $source->unsetRelation('links');
        $pages = [[
            'page' => 1,
            'text' => "Beginner Spanish\nUnit 1: Greetings\nDuration: 6 sessions\nBig Idea: Communicate with confidence.\nLearning Goals\n• Greet someone\nVocabulary\n• hola\nUseful Phrases\n• Buenos días\nGrowing Unit Project: Conversation Portfolio\nBuilds\n• Greeting scene\nChallenge Activities\n• Record a dialogue\nEvidence of Learning\n• Perform the dialogue",
            'items' => [],
        ], [
            'page' => 2,
            'text' => "Beginner Spanish\nUnit 2 – Family\nDuration: 4 weeks\nBig Idea: Describe people.\nLearning Goals\n• Describe family\nVocabulary\n• familia\nUseful Phrases\n• Esta es mi familia\nGrowing Unit Project: Conversation Portfolio\nBuilds\n• Family scene\nChallenge Activities\n• Interview a partner\nEvidence of Learning\n• Present the scene",
            'items' => [],
        ]];
        $parser = app(StructuredCustomCurriculumParser::class);
        $this->assertSame(.985, $parser->recognitionScore($pages, $source));
        $rows = collect($parser->parse($pages, $source)->proposals);
        $this->assertCount(2, $rows->where('proposalType', 'unit'));
        $this->assertCount(2, $rows->where('componentType', 'resource')->where('name', 'Vocabulary'));
        $this->assertCount(2, $rows->where('componentType', 'project'));
        $this->assertCount(2, $rows->where('componentType', 'project_milestone'));
    }

    public function test_unrelated_documents_fail_closed_and_partial_matches_are_ambiguous(): void
    {
        $source = $this->source();
        $parser = app(StructuredCustomCurriculumParser::class);
        $this->assertSame(0.0, $parser->recognitionScore([['page' => 1, 'text' => 'Assessment Philosophy Course Unit Map 40% - Unit Projects', 'items' => []]], $source));

        $partial = [[
            'page' => 1, 'items' => [],
            'text' => "Unit 1 - One\nBig Idea: One\nLearning Goals\n• Goal\nUnit 2 - Two\nBig Idea: Two",
        ]];
        $this->assertSame(.72, $parser->recognitionScore($partial, $source));
        $capability = (new CurriculumParserRegistry([$parser]))->assess($partial, $source, $source->currentFile);
        $this->assertSame('ambiguous', $capability->state);
        $this->assertStringContainsString('Review the detected structure', $capability->userMessage);
    }

    private function source(): AcademicSource
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Custom Curriculum Family', 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        app(TenantContext::class)->set($tenant, $membership);
        $grade = GradeLevel::create(['code' => 'G5', 'name' => 'Grade 5', 'sort_order' => 5, 'is_active' => true]);
        $year = $tenant->schoolYears()->create(['name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => 180]);
        $provider = EducationProvider::create(['name' => 'Cosmic Quest Academy', 'short_name' => 'CQA', 'provider_type' => 'homeschool', 'country_code' => 'US', 'status' => 'active']);
        $subject = Subject::create(['name' => 'Technology', 'code' => 'TECH', 'sort_order' => 1, 'status' => 'active']);
        $source = AcademicSource::create(['created_by_user_id' => $user->id, 'education_provider_id' => $provider->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'title' => 'Technology', 'source_kind' => 'upload', 'source_category' => 'curriculum', 'authority_level' => 'teacher_created', 'review_status' => 'reviewed', 'processing_status' => 'not_requested']);
        Storage::disk('local')->put("academic-sources/{$source->id}/source.pdf", '%PDF fixture');
        $source->files()->create(['uploaded_by_user_id' => $user->id, 'version_number' => 1, 'current_key' => 'current', 'is_current' => true, 'disk' => 'local', 'stored_path' => "academic-sources/{$source->id}/source.pdf", 'stored_filename' => 'source.pdf', 'original_filename' => 'technology.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 12, 'checksum_sha256' => str_repeat('a', 64), 'uploaded_at' => now()]);
        $source->links()->create(['link_type' => 'subject', 'link_id' => $subject->id]);
        return $source->load(['currentFile', 'gradeLevel', 'schoolYear', 'links']);
    }
}
