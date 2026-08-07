<?php

namespace Tests\Feature;

use App\Models\AcademicSource;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CfisdGrade5ScienceYearAtGlanceParser;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CfisdGrade5ScienceYearAtGlanceParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_science_family_recognition_fails_closed_for_context_and_layout(): void
    {
        $source = $this->source(); $pages = require base_path('tests/Fixtures/cfisd-grade5-science-yag-positioned.php');
        $parser = app(CfisdGrade5ScienceYearAtGlanceParser::class);
        $this->assertSame(.997, $parser->recognitionScore($pages, $source));
        $wrongTitle = $pages; $wrongTitle[0]['text'] = str_replace('Science Year at a Glance', 'Filename only', $wrongTitle[0]['text']);
        $this->assertFalse($parser->supports($wrongTitle, $source));
        $wrongLayout = $pages; $wrongLayout[1]['items'] = [];
        $this->assertFalse($parser->supports($wrongLayout, $source));
        $math = Subject::create(['name' => 'Mathematics', 'code' => 'MATH', 'sort_order' => 2, 'status' => 'active']);
        $source->links()->where('link_type', 'subject')->update(['link_id' => $math->id]); $source->unsetRelation('links');
        $this->assertFalse($parser->supports($pages, $source));
    }

    public function test_science_parser_extracts_periods_merged_units_assessments_teks_components_and_evidence(): void
    {
        $result = app(CfisdGrade5ScienceYearAtGlanceParser::class)->parse(require base_path('tests/Fixtures/cfisd-grade5-science-yag-positioned.php'), $this->source());
        $rows = collect($result->proposals); $periods = $rows->where('proposalType', 'period'); $units = $rows->where('proposalType', 'unit');
        $this->assertSame(['1st Nine Weeks', '2nd Nine Weeks', '3rd Nine Weeks', '4th Nine Weeks'], $periods->pluck('name')->all());
        $this->assertCount(10, $units); $this->assertCount(7, $rows->where('proposalType', 'assessment')); $this->assertCount(10, $rows->where('proposalType', 'component'));
        $ecosystems = $units->firstWhere('name', 'Ecosystems Unit');
        $this->assertSame('2025-10-09', $ecosystems->plannedStartDate); $this->assertSame('2025-11-10', $ecosystems->plannedEndDate); $this->assertSame(18, $ecosystems->estimatedDays);
        $this->assertSame(['5.12A', '5.12B', '5.12C'], $ecosystems->standardCodes);
        $this->assertSame(['5.6A-5.13B'], $units->firstWhere('name', 'TEKS Review Unit')->standardCodes);
        $this->assertTrue($rows->where('proposalType', 'component')->every(fn ($row) => $row->componentType === 'concept' && $row->rawText));
        $this->assertStringContainsString('assigned to 2026-2027', $result->diagnostic);
    }

    private function source(): AcademicSource
    {
        $user = User::factory()->create(); $tenant = Tenant::create(['name' => 'Science Tenant', 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']); app(TenantContext::class)->set($tenant, $membership);
        $grade = GradeLevel::create(['code' => 'G5', 'name' => 'Grade 5', 'sort_order' => 5, 'is_active' => true]);
        $year = $tenant->schoolYears()->create(['name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => 180]);
        $provider = EducationProvider::create(['name' => 'Cypress-Fairbanks Independent School District', 'short_name' => 'CFISD', 'provider_type' => 'public_school_district', 'country_code' => 'US', 'status' => 'active']);
        $subject = Subject::create(['name' => 'Science', 'code' => 'SCI', 'sort_order' => 1, 'status' => 'active']);
        $source = AcademicSource::create(['created_by_user_id' => $user->id, 'education_provider_id' => $provider->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'title' => 'Science PDF', 'source_kind' => 'upload', 'source_category' => 'curriculum', 'authority_level' => 'official_provider', 'review_status' => 'reviewed', 'processing_status' => 'not_requested']);
        $source->links()->create(['link_type' => 'subject', 'link_id' => $subject->id]); return $source->load(['educationProvider', 'gradeLevel', 'links', 'schoolYear']);
    }
}
