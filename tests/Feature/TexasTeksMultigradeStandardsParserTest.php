<?php

namespace Tests\Feature;

use App\Contracts\PdfTextExtractor;
use App\Models\AcademicSource;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\StandardsFramework;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CurriculumParserRegistry;
use App\Services\StandardsDocumentMetadataNormalizer;
use App\Services\TexasTeksMultigradeSocialStudiesParser;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TexasTeksMultigradeStandardsParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_recognizes_and_isolates_grade_five_without_filename_or_other_grades(): void
    {
        [$source] = $this->context(); $pages = require base_path('tests/Fixtures/texas-teks-multigrade-social-studies.php');
        $parser = app(TexasTeksMultigradeSocialStudiesParser::class);
        $this->assertTrue($parser->supports($pages, $source));
        $this->assertSame([20, 26], [20, 26], 'The production document boundaries audited for this safe fixture are pages 20-26.');
        $result = $parser->parse($pages, $source); $rows = collect($result->proposals);
        $this->assertSame('113.16', $result->metadata['section']);
        $this->assertSame('Adopted 2022', $result->metadata['adopted_label']);
        $this->assertSame('August 2024 Update', $result->metadata['version_label']);
        $this->assertSame('2024-2025 school year', $result->metadata['effective_label']);
        $this->assertSame('The provisions of this section shall be implemented by school districts beginning with the 2024-2025 school year.', $result->metadata['implementation_statement']);
        $this->assertSame([2, 4], $result->metadata['source_pages']);
        $this->assertStringContainsString('In Grade 5', $result->metadata['introduction_text']);
        $this->assertStringNotContainsString('Kindergarten introduction', $result->metadata['introduction_text']);
        $this->assertStringNotContainsString('Grade 4 introduction', $rows->pluck('rawText')->implode(' '));
        $this->assertSame(['Citizenship', 'Culture', 'Economics', 'Geography', 'Government', 'History', 'Science, technology, and society', 'Social studies skills'], $rows->where('proposalType', 'strand')->pluck('strand')->sort()->values()->all());
        $this->assertSame(['5.1', '5.2', '5.3', '5.4', '5.5', '5.6', '5.7', '5.8'], $rows->where('proposalType', 'standard')->pluck('standardCode')->values()->all());
        $this->assertSame('5.1A', $rows->firstWhere('proposalType', 'student_expectation')->normalizedCode);
        $this->assertStringContainsString('including the search for religious freedom', $rows->firstWhere('standardCode', '5.1A')->statement);
        $this->assertTrue($rows->every(fn ($row) => $row->plannedStartDate === null && $row->plannedEndDate === null && $row->estimatedDays === null));
        $this->assertSame(0, $rows->whereIn('proposalType', ['period', 'unit', 'assessment'])->count());

        $wrong = $pages; $wrong[0]['text'] = 'Social Studies.pdf';
        $this->assertFalse($parser->supports($wrong, $source), 'Filename-like text alone cannot identify this family.');
        $source->currentFile->update(['original_filename' => 'Social Studies.pdf']);
        $this->assertFalse($parser->supports([['text' => 'Social Studies.pdf', 'items' => []]], $source));
    }

    public function test_metadata_normalizer_extracts_only_reliable_compact_effective_labels(): void
    {
        $normalizer = app(StandardsDocumentMetadataNormalizer::class);
        $this->assertSame('2024-2025 school year', $normalizer->effectiveLabel("beginning with the 2024-\n2025 school year"));
        $this->assertSame('August 1, 2024', $normalizer->effectiveLabel('These provisions are effective August 1, 2024.'));
        $this->assertNull($normalizer->effectiveLabel('These provisions apply when districts are ready.'));
    }

    public function test_subject_grade_absence_and_duplicate_section_fail_safely_while_grade_four_is_reusable(): void
    {
        [$source] = $this->context(); $pages = require base_path('tests/Fixtures/texas-teks-multigrade-social-studies.php');
        $parser = app(TexasTeksMultigradeSocialStudiesParser::class);
        $math = Subject::create(['name' => 'Mathematics', 'code' => 'MATH', 'sort_order' => 2, 'status' => 'active']);
        $source->links()->where('link_type', 'subject')->update(['link_id' => $math->id]); $source->unsetRelation('links');
        $this->assertFalse($parser->supports($pages, $source));
        $ss = Subject::query()->where('code', 'SS')->firstOrFail();
        $source->links()->where('link_type', 'subject')->update(['link_id' => $ss->id]); $source->unsetRelation('links');
        $pagesMissing = $pages; $pagesMissing[1]['text'] = str_replace('§113.16.', '§113.99.', $pagesMissing[1]['text']);
        $this->assertFalse($parser->supports($pagesMissing, $source));

        $duplicate = $pages; $duplicate[] = ['text' => $pages[1]['text'], 'items' => []];
        $capability = (new CurriculumParserRegistry([$parser]))->assess($duplicate, $source, $source->currentFile);
        $this->assertSame('ambiguous', $capability->state);

        $grade4 = GradeLevel::create(['name' => 'Grade 4', 'code' => 'G4', 'sort_order' => 4, 'is_active' => true]);
        $source->update(['grade_level_id' => $grade4->id]); $source->unsetRelation('gradeLevel');
        $grade4Result = $parser->parse($pages, $source);
        $this->assertSame('113.15', $grade4Result->metadata['section']);
        $this->assertSame(['4.1'], collect($grade4Result->proposals)->where('proposalType', 'standard')->pluck('standardCode')->all());
        $this->assertStringNotContainsString('Grade 5', collect($grade4Result->proposals)->pluck('rawText')->implode(' '));
    }

    private function context(): array
    {
        $user = User::factory()->create(); $tenant = Tenant::create(['name' => 'Standards Academy', 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']); app(TenantContext::class)->set($tenant, $membership);
        $grade = GradeLevel::create(['name' => 'Grade 5', 'code' => 'G5', 'sort_order' => 5, 'is_active' => true]);
        $year = $tenant->schoolYears()->create(['name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => 180]);
        $provider = EducationProvider::create(['name' => 'CFISD', 'short_name' => 'CFISD', 'provider_type' => 'public_school_district', 'country_code' => 'US', 'status' => 'active']);
        $framework = StandardsFramework::create(['education_provider_id' => $provider->id, 'name' => 'Texas Essential Knowledge and Skills', 'short_name' => 'TEKS', 'jurisdiction' => 'Texas', 'version_label' => '2022', 'status' => 'active']);
        $subject = Subject::create(['name' => 'Social Studies', 'code' => 'SS', 'sort_order' => 1, 'status' => 'active']);
        $source = AcademicSource::create(['created_by_user_id' => $user->id, 'education_provider_id' => $provider->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'title' => '5th - SS', 'source_kind' => 'upload', 'source_category' => 'curriculum', 'authority_level' => 'official_provider', 'review_status' => 'reviewed', 'processing_status' => 'not_requested']);
        Storage::fake('local'); Storage::disk('local')->put("academic-sources/{$source->id}/ss.pdf", '%PDF fixture');
        $source->files()->create(['uploaded_by_user_id' => $user->id, 'version_number' => 1, 'current_key' => 'current', 'is_current' => true, 'disk' => 'local', 'stored_path' => "academic-sources/{$source->id}/ss.pdf", 'stored_filename' => 'ss.pdf', 'original_filename' => 'Social Studies.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 12, 'checksum_sha256' => str_repeat('d', 64), 'uploaded_at' => now()]);
        $source->links()->create(['link_type' => 'subject', 'link_id' => $subject->id]); $source->links()->create(['link_type' => 'standards_framework', 'link_id' => $framework->id]);
        return [$source->load(['currentFile', 'gradeLevel', 'links']), $user, $tenant];
    }
}
