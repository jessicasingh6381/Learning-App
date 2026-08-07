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
use App\Services\CurriculumParserCapabilityService;
use App\Services\CurriculumParserRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CurriculumFormatOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private object $extractor;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); Storage::fake('local');
        $this->extractor = new class implements PdfTextExtractor {
            public array $pages = [];
            public function extract(string $absolutePath): array { return $this->pages; }
        };
        $this->app->instance(PdfTextExtractor::class, $this->extractor);
    }

    public function test_readable_unsupported_source_is_onboarded_idempotently_without_creating_an_import(): void
    {
        [$owner, $tenant, $source] = $this->context(); $this->extractor->pages = $this->pages();
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-capability.store', $source))->assertSessionHasNoErrors();
        $before = app(CurriculumParserCapabilityService::class)->cached($source->fresh('currentFile'));
        $this->assertSame('unsupported', $before->state);
        $this->actingIn($owner, $tenant)->get(route('academic.sources.show', $source))->assertInertia(fn (Assert $page) => $page
            ->where('curriculumSetup.workflow_state', 'format_setup_needed')
            ->where('curriculumSetup.primary_action_label', 'Set up this document format')
            ->where('curriculumSetup.primary_action_url', route('academic.sources.curriculum-format-setup.create', $source)));
        $this->actingIn($owner, $tenant)->get(route('academic.sources.curriculum-format-setup.create', $source))->assertInertia(fn (Assert $page) => $page
            ->component('Academic/CurriculumFormats/Show')->where('profile', null)->where('detected.title', 'Custom Curriculum Map 2026-2027')
            ->where('detected.headings.0', 'Quarter 1')->where('detected.unit_rows.0', 'Unit 1: Cells'));

        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-format-setup.store', $source))->assertRedirect();
        $profile = CurriculumFormatProfile::query()->firstOrFail();
        $this->assertSame('draft', $profile->status); $this->assertDatabaseCount('curriculum_imports', 0);
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-format-setup.store', $source))->assertRedirect(route('academic.curriculum-format-profiles.show', $profile));
        $this->assertDatabaseCount('curriculum_format_profiles', 1);
        $this->assertSame('unsupported', app(CurriculumParserCapabilityService::class)->assess($source->fresh('currentFile'), true)->state);

        $mapping = [
            'name' => 'Custom quarterly curriculum map', 'document_family' => 'Custom homeschool curriculum map',
            'strategy' => 'confirmed_heading_rows', 'confirmed_period_headings' => ['Quarter 1'],
            'confirmed_unit_rows' => ['Unit 1: Cells'], 'confirmed_assessment_rows' => ['Assessment: Cell model'],
        ];
        $this->actingIn($owner, $tenant)->put(route('academic.curriculum-format-profiles.update', $profile), $mapping)->assertSessionHasNoErrors();
        $signatureBeforeActivation = app(CurriculumParserRegistry::class)->signature();
        $this->actingIn($owner, $tenant)->post(route('academic.curriculum-format-profiles.activate', $profile))->assertRedirect(route('academic.sources.show', $source));
        $this->assertSame('active', $profile->fresh()->status); $this->assertNotSame($signatureBeforeActivation, app(CurriculumParserRegistry::class)->signature());
        $this->assertSame('supported', app(CurriculumParserCapabilityService::class)->cached($source->fresh('currentFile'))->state);
        $this->assertDatabaseCount('curriculum_imports', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'curriculum-format-profile.activated']);
    }

    public function test_profiles_are_tenant_safe_authorized_declarative_and_ambiguous_matches_do_not_auto_select(): void
    {
        [$owner, $tenant, $source] = $this->context(); $this->extractor->pages = $this->pages();
        $this->actingIn($owner, $tenant)->post(route('academic.sources.curriculum-format-setup.store', $source));
        $profile = CurriculumFormatProfile::query()->firstOrFail();
        $bad = ['name' => 'Bad', 'document_family' => 'Bad', 'strategy' => 'php_code', 'confirmed_period_headings' => ['Forged'], 'confirmed_unit_rows' => ['Forged'], 'confirmed_assessment_rows' => []];
        $this->actingIn($owner, $tenant)->put(route('academic.curriculum-format-profiles.update', $profile), $bad)->assertSessionHasErrors('strategy');
        $bad['strategy'] = 'confirmed_heading_rows';
        $this->actingIn($owner, $tenant)->put(route('academic.curriculum-format-profiles.update', $profile), $bad)->assertSessionHasErrors('confirmed_period_headings');

        $parent = User::factory()->create(); TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $parent->id, 'role' => 'parent', 'status' => 'active']);
        $this->actingIn($parent, $tenant)->post(route('academic.curriculum-format-profiles.activate', $profile))->assertForbidden();
        [$otherOwner, $otherTenant] = $this->tenantUser('Other');
        $this->actingIn($otherOwner, $otherTenant)->get(route('academic.curriculum-format-profiles.show', $profile->id))->assertNotFound();

        $mapping = ['name' => 'One', 'document_family' => 'Custom map', 'strategy' => 'confirmed_heading_rows', 'confirmed_period_headings' => ['Quarter 1'], 'confirmed_unit_rows' => ['Unit 1: Cells'], 'confirmed_assessment_rows' => []];
        $this->actingIn($owner, $tenant)->put(route('academic.curriculum-format-profiles.update', $profile), $mapping);
        $this->actingIn($owner, $tenant)->post(route('academic.curriculum-format-profiles.activate', $profile));
        $duplicate = $profile->fresh()->replicate(['example_academic_source_id', 'example_academic_source_file_id', 'created_by_user_id', 'reviewed_by_user_id']);
        $duplicate->name = 'Equal match'; $duplicate->status = 'active'; $duplicate->activated_at = now(); $duplicate->save();
        $capability = app(CurriculumParserCapabilityService::class)->assess($source->fresh('currentFile'), true);
        $this->assertSame('ambiguous', $capability->state);
        $wrongPages = $this->pages(); $wrongPages[0]['text'] = str_replace('Custom Curriculum Map 2026-2027', 'Same filename, wrong content', $wrongPages[0]['text']);
        $this->assertTrue(collect(app(CurriculumParserRegistry::class)->applicable($source, $source->currentFile))->every(fn ($parser) => $parser->recognitionScore($wrongPages, $source) === 0.0));
    }

    private function pages(): array
    {
        $items = [['text' => 'Custom Curriculum Map 2026-2027', 'x' => 10, 'y' => 100], ['text' => 'Quarter 1', 'x' => 10, 'y' => 90], ['text' => 'Unit 1: Cells', 'x' => 10, 'y' => 80], ['text' => 'Assessment: Cell model', 'x' => 10, 'y' => 70]];
        return [['page' => 1, 'text' => "Custom Curriculum Map 2026-2027\nQuarter 1\nUnit 1: Cells\nAssessment: Cell model", 'items' => $items]];
    }

    private function context(): array
    {
        [$owner, $tenant] = $this->tenantUser('Onboarding');
        $grade = GradeLevel::create(['code' => 'G5', 'name' => 'Grade 5', 'sort_order' => 5, 'is_active' => true]);
        $year = $tenant->schoolYears()->create(['name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'timezone' => 'America/Chicago', 'status' => 'active', 'instructional_day_target' => 180]);
        $provider = EducationProvider::create(['name' => 'Custom Provider', 'short_name' => 'CUSTOM', 'provider_type' => 'other', 'country_code' => 'US', 'status' => 'active']);
        $subject = Subject::create(['name' => 'Life Studies', 'code' => 'LIFE', 'sort_order' => 1, 'status' => 'active']);
        $source = AcademicSource::create(['created_by_user_id' => $owner->id, 'education_provider_id' => $provider->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'title' => 'custom.pdf', 'source_kind' => 'upload', 'source_category' => 'curriculum', 'authority_level' => 'teacher_selected', 'review_status' => 'reviewed', 'processing_status' => 'not_requested']);
        Storage::disk('local')->put("academic-sources/{$source->id}/custom.pdf", '%PDF');
        $source->files()->create(['uploaded_by_user_id' => $owner->id, 'version_number' => 1, 'current_key' => 'current', 'is_current' => true, 'disk' => 'local', 'stored_path' => "academic-sources/{$source->id}/custom.pdf", 'stored_filename' => 'custom.pdf', 'original_filename' => 'custom.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 4, 'checksum_sha256' => str_repeat('b', 64), 'uploaded_at' => now()]);
        $source->links()->create(['link_type' => 'subject', 'link_id' => $subject->id]);
        return [$owner, $tenant, $source->load(['currentFile', 'educationProvider', 'gradeLevel', 'schoolYear', 'links'])];
    }

    private function tenantUser(string $name): array
    {
        $user = User::factory()->create(); $tenant = Tenant::create(['name' => $name, 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']); app(TenantContext::class)->set($tenant, $membership); return [$user, $tenant];
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        $membership = TenantMembership::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail(); app(TenantContext::class)->set($tenant, $membership); return $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);
    }
}
