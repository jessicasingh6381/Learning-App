<?php

namespace Tests\Feature;

use App\Models\AcademicSource;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CfisdGrade5ElarParentYearAtGlanceParser;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CfisdGrade5ElarParentYearAtGlanceParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_layout_applicability_and_positioned_recognition_fail_closed(): void
    {
        $source = $this->source();
        $parser = app(CfisdGrade5ElarParentYearAtGlanceParser::class);
        $pages = require base_path('tests/Fixtures/cfisd-grade5-elar-yag-positioned.php');
        $meta = $parser->applicability();
        $this->assertContains('CFISD', $meta->providerCodes);
        $this->assertContains('ELAR', $meta->subjectCodes);
        $this->assertContains('G5', $meta->gradeCodes);
        $this->assertSame('Parent Year at a Glance', $meta->documentFamily);
        $this->assertSame(.995, $parser->recognitionScore($pages, $source));

        $withoutItems = $pages; $withoutItems[0]['items'] = [];
        $this->assertFalse($parser->supports($withoutItems, $source));
        $teacher = $pages; $teacher[0]['text'] .= "\nTeacher Edition";
        $this->assertFalse($parser->supports($teacher, $source));
        $source->educationProvider->update(['short_name' => 'OTHER', 'name' => 'Other']); $source->unsetRelation('educationProvider');
        $this->assertFalse($parser->supports($pages, $source));
        $source->educationProvider->update(['short_name' => 'CFISD', 'name' => 'Cypress-Fairbanks Independent School District']); $source->unsetRelation('educationProvider');
        $source->gradeLevel->update(['code' => 'G6', 'name' => 'Grade 6']); $source->unsetRelation('gradeLevel');
        $this->assertFalse($parser->supports($pages, $source));
        $source->gradeLevel->update(['code' => 'G5', 'name' => 'Grade 5']); $source->unsetRelation('gradeLevel');
        $math = Subject::create(['name' => 'Mathematics', 'code' => 'MATH', 'sort_order' => 2, 'status' => 'active']);
        $source->links()->where('link_type', 'subject')->update(['link_id' => $math->id]); $source->unsetRelation('links');
        $this->assertFalse($parser->supports($pages, $source));
    }

    public function test_real_layout_fixture_extracts_periods_units_assessments_parallel_tracks_and_provenance(): void
    {
        $source = $this->source();
        $result = app(CfisdGrade5ElarParentYearAtGlanceParser::class)
            ->parse(require base_path('tests/Fixtures/cfisd-grade5-elar-yag-positioned.php'), $source);
        $proposals = collect($result->proposals);
        $periods = $proposals->where('proposalType', 'period')->values();
        $units = $proposals->where('proposalType', 'unit')->values();
        $components = $proposals->where('proposalType', 'component')->values();
        $this->assertCount(4, $periods);
        $this->assertSame(['1st Grading Period', '2nd Grading Period', '3rd Grading Period', '4th Grading Period'], $periods->pluck('name')->all());
        $this->assertCount(12, $units);
        $this->assertSame(range(1, 12), $units->map(fn ($unit) => (int) str_replace('Unit ', '', $unit->name))->all());
        $this->assertSame('2026-08-12', $units[0]->plannedStartDate);
        $this->assertSame('2026-09-04', $units[0]->plannedEndDate);
        $this->assertSame('2026-09-28', $units[2]->plannedStartDate);
        $this->assertSame('2026-10-23', $units[2]->plannedEndDate);
        $this->assertSame(['BOY MAP Growth', 'DPM 1', 'DPM 2', 'DPM 3', 'RLA STAAR', 'EOY MAP Growth'], $proposals->where('proposalType', 'assessment')->pluck('name')->all());
        $this->assertSame('Reading: Launching Literacy and HMH Module 1: Inventors at Work · Writing: Launching Literacy and HMH Module 3: Argument', $units[0]->summary);
        foreach (['Reading', 'Writing', 'Editing and Grammar', 'Foundational Skills', 'Handwriting Without Tears', 'Integrated Social Studies', 'Focus TEKS Evidence'] as $name) {
            $this->assertTrue($components->contains('name', $name), "Missing {$name} component.");
        }
        foreach (['Launching Literacy', 'HMH Module 1: Inventors at Work', 'HMH Module 3: Argument', 'Persuasive Essay', 'Narrative Nonfiction', 'Central Ideas', 'Monitor and Clarify', "Author's Craft", 'Text Evidence', 'Text Structure (cause and effect; problem and solution)', 'Sentence Boundaries', 'Clarity and Style', 'Extended Constructed Response support'] as $name) {
            $this->assertTrue($components->contains('name', $name), "Missing {$name} child component. Found: ".$components->pluck('name')->unique()->implode(' | '));
        }
        $this->assertSame(1, $components->where('name', 'Central Ideas')->count());
        $this->assertSame('revising', $components->firstWhere('name', 'Sentence Boundaries')->componentType);
        $readingRoot = $components->firstWhere(fn ($component) => $component->name === 'Reading' && str_contains($component->key, '1:root'));
        $module = $components->firstWhere('name', 'HMH Module 1: Inventors at Work');
        $this->assertSame($readingRoot->key, $module->parentKey);
        $ecr = $components->firstWhere('name', 'Extended Constructed Response support');
        $this->assertSame('assessment_support', $ecr->componentType);
        $this->assertTrue($components->contains(fn ($component) => $component->parentKey === $ecr->key && $component->name === 'ECR Success Criteria'));
        $ambiguous = $components->firstWhere('name', 'Writing Process');
        $this->assertSame(.74, $ambiguous->confidence);
        $this->assertStringContainsString('Comma boundaries are ambiguous', $ambiguous->parserNote);
        $parenthetical = $components->firstWhere('name', 'Text Structure (cause and effect; problem and solution)');
        $this->assertStringContainsString('cause and effect; problem and solution', $parenthetical->rawText);
        $this->assertFalse($components->contains('name', 'Make Inferences'));
        $this->assertFalse($components->contains(fn ($component) => str_contains($component->name, 'Literacy Author') || str_contains($component->name, 'Text Lessons')));
        $focus = $components->firstWhere('name', 'Focus TEKS Evidence');
        $this->assertSame(.65, $focus->confidence);
        $this->assertStringContainsString('without inventing standard codes', $focus->parserNote);
        $this->assertTrue($proposals->every(fn ($proposal) => $proposal->standardCodes === []));
        $this->assertTrue($components->every(fn ($component) => $component->sourcePage >= 1 && $component->rawText !== null && $component->rawText !== ''));
        $this->assertSame('2026-06-30', $result->revisionDate);
    }

    private function source(): AcademicSource
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => 'ELAR Family', 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        app(TenantContext::class)->set($tenant, $membership);
        $grade = GradeLevel::create(['code' => 'G5', 'name' => 'Grade 5', 'sort_order' => 5, 'is_active' => true]);
        $year = $tenant->schoolYears()->create(['name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => 180]);
        $provider = EducationProvider::create(['name' => 'Cypress-Fairbanks Independent School District', 'short_name' => 'CFISD', 'provider_type' => 'public_school_district', 'country_code' => 'US', 'status' => 'active']);
        $subject = Subject::create(['name' => 'English Language Arts and Reading', 'code' => 'ELAR', 'sort_order' => 1, 'status' => 'active']);
        $source = AcademicSource::create(['created_by_user_id' => $user->id, 'education_provider_id' => $provider->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'title' => 'Grade 5 ELAR Parent YAG', 'source_kind' => 'upload', 'source_category' => 'curriculum', 'authority_level' => 'official_provider', 'review_status' => 'reviewed', 'processing_status' => 'not_requested']);
        $source->links()->create(['link_type' => 'subject', 'link_id' => $subject->id]);

        return $source->load(['educationProvider', 'gradeLevel', 'links', 'schoolYear']);
    }
}
