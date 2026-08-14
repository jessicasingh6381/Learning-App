<?php

namespace Tests\Feature;

use App\Models\AcademicSource;
use App\Models\AcademicYearConfiguration;
use App\Models\Course;
use App\Models\CurriculumImport;
use App\Models\CurriculumPackage;
use App\Models\CurriculumPeriod;
use App\Models\CurriculumUnit;
use App\Models\CurriculumUnitComponent;
use App\Models\CurriculumUnitStandardAlignment;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\LearningPlanSubjectPreference;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\StandardsFramework;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CurriculumIntakeService;
use App\Services\StructuredCustomCurriculumParser;
use App\Tenancy\TenantContext;
use Database\Seeders\AcademicReferenceSeeder;
use Database\Seeders\GradeLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CurriculumIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([GradeLevelSeeder::class, AcademicReferenceSeeder::class]);
        Storage::fake('local');
    }

    public function test_authorized_adults_see_default_enrollment_provider_and_subject_context(): void
    {
        [$owner, $tenant] = $this->adult();
        [$student, $year] = $this->enrollment($owner, $tenant);

        $this->actingIn($owner, $tenant)->get('/learning-plan/curriculum-intake')->assertInertia(fn (Assert $page) => $page
            ->component('Workspace/CurriculumIntake')
            ->where('selectedContext.student_id', $student->id)
            ->where('selectedContext.student_name', 'Kai Singh')
            ->where('selectedContext.school_year_id', $year->id)
            ->where('selectedContext.school_year_name', '2026-2027')
            ->where('selectedContext.grade_name', 'Grade 5')
            ->where('providers.0.short_name', 'CFISD')
            ->has('subjects', 12)
            ->where('subjects.0.name', 'English Language Arts and Reading')
            ->where('subjects.1.name', 'Mathematics')
            ->where('subjects.1.status', 'not_started')
            ->where('subjects.1.primary_action_label', 'Add curriculum source')
            ->where('subjects.1.primary_action_url', route('workspace.curriculum-intake.subject.create', [
                'student' => $student->id, 'schoolYear' => $year->id,
                'subject' => Subject::query()->where('code', 'MATH')->value('id'),
            ])));

        $parent = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $parent->id, 'role' => 'parent', 'status' => 'active']);
        $this->actingIn($parent, $tenant)->get('/learning-plan/curriculum-intake')->assertOk();
    }

    public function test_subject_specific_entry_resolves_context_refreshes_directly_and_preserves_back_destinations(): void
    {
        [$owner, $tenant] = $this->adult();
        [$student, $year] = $this->enrollment($owner, $tenant);
        $configuredProvider = EducationProvider::query()->where('short_name', 'CFISD')->firstOrFail();
        EducationProvider::create(['name' => 'Alternate Tenant Provider', 'provider_type' => 'district', 'status' => 'active']);
        AcademicYearConfiguration::create([
            'school_year_id' => $year->id,
            'education_provider_id' => $configuredProvider->id,
            'status' => 'draft',
        ]);
        $math = Subject::query()->where('code', 'MATH')->firstOrFail();
        $url = $this->subjectCreateUrl($student->id, $year->id, $math->id);

        $this->actingIn($owner, $tenant)->get($url)->assertInertia(fn (Assert $page) => $page
            ->component('Workspace/CurriculumIntake')
            ->where('entryMode', 'add')
            ->where('selectedContext.student_name', 'Kai Singh')
            ->where('selectedContext.school_year_name', '2026-2027')
            ->where('selectedContext.grade_name', 'Grade 5')
            ->where('selectedSubject.name', 'Mathematics')
            ->where('contextProvider.name', 'Cypress-Fairbanks Independent School District')
            ->where('returnTo', 'learning-plan')
            ->where('backUrl', route('workspace.learning-plan', ['student_id' => $student->id])));

        $this->actingIn($owner, $tenant)->get($url.'?from=overview')->assertInertia(fn (Assert $page) => $page
            ->where('entryMode', 'add')
            ->where('returnTo', 'overview')
            ->where('backUrl', route('workspace.curriculum-intake', ['student_id' => $student->id, 'school_year_id' => $year->id])));

        $this->actingIn($owner, $tenant)->get('/learning-plan/curriculum-intake?student_id='.$student->id.'&school_year_id='.$year->id.'&subject_id='.$math->id)
            ->assertRedirect($url);
    }

    public function test_subject_specific_validation_returns_to_the_same_context_and_rejects_forged_fields(): void
    {
        [$owner, $tenant] = $this->adult();
        [$student, $year] = $this->enrollment($owner, $tenant);
        $math = Subject::query()->where('code', 'MATH')->firstOrFail();
        $createUrl = $this->subjectCreateUrl($student->id, $year->id, $math->id);
        $storeUrl = $this->subjectStoreUrl($student->id, $year->id, $math->id);

        $this->actingIn($owner, $tenant)->from($createUrl)->post($storeUrl, [
            'title' => 'Remember this title', 'source_kind' => 'upload',
        ])->assertRedirect($createUrl)->assertSessionHasErrors('source_file')->assertSessionHasInput('title', 'Remember this title');

        $this->actingIn($owner, $tenant)->from($createUrl)->post($storeUrl, [
            'title' => 'Forged context', 'source_kind' => 'manual', 'manual_reference' => 'Printed guide',
            'student_id' => 999, 'school_year_id' => 999, 'subject_id' => 999,
            'education_provider_id' => 999, 'source_origin' => 'other',
        ])->assertSessionHasErrors(['student_id', 'school_year_id', 'subject_id', 'education_provider_id', 'source_origin']);
        $this->assertDatabaseCount('academic_sources', 0);
    }

    public function test_subject_specific_context_rejects_foreign_records_missing_enrollment_and_student_users(): void
    {
        [$ownerA, $tenantA] = $this->adult('owner', 'Academy A');
        [$studentA, $yearA] = $this->enrollment($ownerA, $tenantA);
        $math = Subject::query()->where('code', 'MATH')->firstOrFail();

        [$ownerB, $tenantB] = $this->adult('owner', 'Academy B');
        [$studentB, $yearB] = $this->enrollment($ownerB, $tenantB, 'Other Student');
        $this->setContext($ownerB, $tenantB);
        $foreignSubject = Subject::create(['name' => 'Foreign Robotics', 'code' => 'FOREIGN-ROBOTICS', 'sort_order' => 99, 'status' => 'active']);
        $foreignProvider = EducationProvider::create(['name' => 'Foreign Provider', 'provider_type' => 'district', 'status' => 'active']);

        $this->actingIn($ownerA, $tenantA)->get($this->subjectCreateUrl($studentB->id, $yearA->id, $math->id))->assertNotFound();
        $this->actingIn($ownerA, $tenantA)->get($this->subjectCreateUrl($studentA->id, $yearB->id, $math->id))->assertNotFound();
        $this->actingIn($ownerA, $tenantA)->get($this->subjectCreateUrl($studentA->id, $yearA->id, $foreignSubject->id))->assertNotFound();

        $storeUrl = $this->subjectStoreUrl($studentA->id, $yearA->id, $math->id);
        $this->actingIn($ownerA, $tenantA)->post($storeUrl, [
            ...$this->subjectPayload(['source_kind' => 'manual', 'manual_reference' => 'Printed guide']),
            'education_provider_id' => $foreignProvider->id,
        ])->assertSessionHasErrors('education_provider_id');

        $this->setContext($ownerA, $tenantA);
        $unenrolled = $tenantA->students()->create(['first_name' => 'No', 'last_name' => 'Enrollment', 'status' => 'active']);
        $this->actingIn($ownerA, $tenantA)->get($this->subjectCreateUrl($unenrolled->id, $yearA->id, $math->id))->assertNotFound();

        $studentUser = User::factory()->create(['email' => null, 'username' => 'curriculum.student']);
        TenantMembership::create(['tenant_id' => $tenantA->id, 'user_id' => $studentUser->id, 'role' => 'student', 'status' => 'active']);
        $studentA->update(['user_id' => $studentUser->id, 'student_access_enabled_at' => now()]);
        $this->actingIn($studentUser, $tenantA)->get($this->subjectCreateUrl($studentA->id, $yearA->id, $math->id))->assertForbidden();
    }

    public function test_subject_specific_pdf_url_and_manual_sources_save_then_return_to_updated_overview(): void
    {
        [$owner, $tenant] = $this->adult();
        [$student, $year, $grade] = $this->enrollment($owner, $tenant);
        $provider = EducationProvider::query()->where('short_name', 'CFISD')->firstOrFail();
        $math = Subject::query()->where('code', 'MATH')->firstOrFail();
        $url = $this->subjectStoreUrl($student->id, $year->id, $math->id);
        $overview = route('workspace.curriculum-intake', ['student_id' => $student->id, 'school_year_id' => $year->id]);

        $this->actingIn($owner, $tenant)->post($url, $this->subjectPayload([
            'source_file' => $this->pdf('direct-math.pdf'),
        ]))->assertRedirect($overview);
        $this->actingIn($owner, $tenant)->get($overview)->assertInertia(fn (Assert $page) => $page
            ->where('entryMode', 'overview')->where('subjects.1.status', 'source_awaiting_review')
            ->where('subjects.1.primary_action_label', 'Review source'));

        $this->actingIn($owner, $tenant)->post($url, $this->subjectPayload([
            'title' => 'Direct URL', 'source_kind' => 'url', 'source_url' => 'https://example.edu/direct-math',
        ]))->assertRedirect($overview);
        $this->actingIn($owner, $tenant)->post($url, $this->subjectPayload([
            'title' => 'Direct Manual', 'source_kind' => 'manual', 'manual_reference' => 'Printed curriculum guide.',
        ]))->assertRedirect($overview);

        $this->assertDatabaseCount('academic_sources', 3);
        $this->assertDatabaseHas('academic_sources', [
            'tenant_id' => $tenant->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id,
            'education_provider_id' => $provider->id, 'source_category' => 'curriculum',
        ]);
        $this->assertDatabaseHas('academic_sources', ['source_kind' => 'url', 'source_url' => 'https://example.edu/direct-math']);
        $this->assertDatabaseHas('academic_sources', ['source_kind' => 'manual', 'description' => 'Printed curriculum guide.']);
        $this->assertDatabaseCount('academic_source_files', 1);
    }

    public function test_pdf_url_and_manual_intake_reuse_source_links_and_private_storage(): void
    {
        [$owner, $tenant] = $this->adult();
        [$student, $year, $grade] = $this->enrollment($owner, $tenant);
        $provider = EducationProvider::query()->where('short_name', 'CFISD')->firstOrFail();
        $math = Subject::query()->where('code', 'MATH')->firstOrFail();

        $this->actingIn($owner, $tenant)->post('/learning-plan/curriculum-intake', $this->payload($student->id, $year->id, $provider->id, $math->id, [
            'source_file' => UploadedFile::fake()->createWithContent('notes.txt', 'Not a PDF'),
        ]))->assertSessionHasErrors('source_file');
        $this->assertDatabaseCount('academic_sources', 0);

        $this->actingIn($owner, $tenant)->post('/learning-plan/curriculum-intake', $this->payload($student->id, $year->id, $provider->id, $math->id, [
            'source_file' => $this->pdf('grade-5-mathematics.pdf'),
        ]))->assertRedirect();
        $pdfSource = AcademicSource::query()->where('source_kind', 'upload')->firstOrFail();
        $file = $pdfSource->currentFile()->firstOrFail();
        $this->assertSame('grade-5-mathematics.pdf', $file->original_filename);
        $this->assertNotSame($file->original_filename, $file->stored_filename);
        Storage::disk('local')->assertExists($file->stored_path);
        $this->assertDatabaseHas('academic_sources', [
            'id' => $pdfSource->id, 'tenant_id' => $tenant->id, 'school_year_id' => $year->id,
            'grade_level_id' => $grade->id, 'education_provider_id' => $provider->id,
            'source_category' => 'curriculum', 'review_status' => 'unreviewed',
        ]);
        foreach (['education_provider' => $provider->id, 'school_year' => $year->id, 'grade_level' => $grade->id, 'subject' => $math->id] as $type => $id) {
            $this->assertDatabaseHas('academic_source_links', ['academic_source_id' => $pdfSource->id, 'link_type' => $type, 'link_id' => $id]);
        }

        $this->actingIn($owner, $tenant)->post('/learning-plan/curriculum-intake', $this->payload($student->id, $year->id, $provider->id, $math->id, [
            'title' => 'Grade 5 Mathematics URL', 'source_kind' => 'url', 'source_file' => null,
            'source_url' => 'https://www.example.edu/grade-5-mathematics',
        ]))->assertRedirect();
        $this->actingIn($owner, $tenant)->post('/learning-plan/curriculum-intake', $this->payload($student->id, $year->id, $provider->id, $math->id, [
            'title' => 'Family Mathematics Reference', 'source_origin' => 'custom',
            'education_provider_id' => null, 'source_kind' => 'manual', 'source_file' => null,
            'manual_reference' => 'Family-owned printed curriculum reference.',
        ]))->assertRedirect();

        $this->assertDatabaseCount('academic_sources', 3);
        $this->assertDatabaseHas('academic_sources', ['source_kind' => 'url', 'source_url' => 'https://www.example.edu/grade-5-mathematics']);
        $this->assertDatabaseHas('academic_sources', ['source_kind' => 'manual', 'authority_level' => 'tenant_created', 'description' => 'Family-owned printed curriculum reference.']);
        $this->assertDatabaseCount('academic_source_files', 1);
        $this->assertFalse(Schema::hasTable('units'));
        $this->assertTrue(Schema::hasTable('lesson_plans'));
        $this->assertTrue(Schema::hasTable('lessons'));
        $this->assertFalse(Schema::hasTable('assignments'));
    }

    public function test_client_ownership_overrides_and_cross_tenant_context_are_rejected(): void
    {
        [$ownerA, $tenantA] = $this->adult('owner', 'Academy A');
        [$studentA, $yearA] = $this->enrollment($ownerA, $tenantA);
        [$ownerB, $tenantB] = $this->adult('owner', 'Academy B');
        [$studentB, $yearB] = $this->enrollment($ownerB, $tenantB, 'Other Student');
        $this->setContext($ownerA, $tenantA);
        $provider = EducationProvider::query()->firstOrFail();
        $subject = Subject::query()->where('code', 'SCI')->firstOrFail();

        $this->actingIn($ownerA, $tenantA)->post('/learning-plan/curriculum-intake', [
            ...$this->payload($studentA->id, $yearA->id, $provider->id, $subject->id),
            'tenant_id' => $tenantB->id,
        ])->assertSessionHasErrors('tenant_id');
        $this->actingIn($ownerA, $tenantA)->post('/learning-plan/curriculum-intake', $this->payload($studentB->id, $yearA->id, $provider->id, $subject->id))
            ->assertSessionHasErrors('student_id');
        $this->actingIn($ownerA, $tenantA)->post('/learning-plan/curriculum-intake', $this->payload($studentA->id, $yearB->id, $provider->id, $subject->id))
            ->assertSessionHasErrors(['school_year_id', 'student_id']);
        $this->assertDatabaseCount('academic_sources', 0);
    }

    public function test_review_states_and_metadata_only_draft_are_idempotent_and_visible_in_learning_plan(): void
    {
        [$owner, $tenant] = $this->adult();
        [$student, $year] = $this->enrollment($owner, $tenant);
        $provider = EducationProvider::query()->firstOrFail();
        $math = Subject::query()->where('code', 'MATH')->firstOrFail();
        $this->actingIn($owner, $tenant)->post('/learning-plan/curriculum-intake', $this->payload($student->id, $year->id, $provider->id, $math->id, [
            'source_file' => $this->pdf('math.pdf'),
        ]));
        $source = AcademicSource::query()->firstOrFail();

        $this->actingIn($owner, $tenant)->get('/learning-plan/curriculum-intake')->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.status', 'source_awaiting_review')->where('subjects.1.status_label', 'Source awaiting review')
            ->where('subjects.1.primary_action_label', 'Review source')
            ->where('subjects.1.primary_action_url', route('academic.sources.show', $source)));
        $this->actingIn($owner, $tenant)->patch("/academic-setup/sources/{$source->id}/review", ['review_status' => 'in_review'])->assertRedirect();
        $this->actingIn($owner, $tenant)->get('/learning-plan/curriculum-intake')->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.status', 'source_awaiting_review'));
        $this->actingIn($owner, $tenant)->patch("/academic-setup/sources/{$source->id}/review", ['review_status' => 'reviewed'])->assertRedirect();
        $this->actingIn($owner, $tenant)->get('/learning-plan/curriculum-intake')->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.status', 'outline_support_unknown')
            ->where('subjects.1.primary_action_label', 'Check outline support'));

        $this->actingIn($owner, $tenant)->post("/learning-plan/curriculum-intake/sources/{$source->id}/draft")->assertRedirect();
        $package = CurriculumPackage::query()->firstOrFail();
        $this->actingIn($owner, $tenant)->post("/learning-plan/curriculum-intake/sources/{$source->id}/draft")->assertRedirect(route('academic.curriculum.show', $package));
        $this->assertDatabaseCount('curriculum_packages', 1);
        $this->assertDatabaseCount('curriculum_package_courses', 0);
        $this->assertNull($package->description);
        $this->assertNull($package->standards_framework_id);
        $this->assertDatabaseHas('academic_source_links', ['academic_source_id' => $source->id, 'link_type' => 'curriculum_package', 'link_id' => $package->id]);
        $this->actingIn($owner, $tenant)->get('/learning-plan/curriculum-intake')->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.status', 'outline_support_unknown')->where('subjects.1.status_label', 'Outline support not checked')
            ->where('subjects.1.primary_action_label', 'Check outline support'));
        $this->actingIn($owner, $tenant)->get('/learning-plan?student_id='.$student->id)->assertInertia(fn (Assert $page) => $page
            ->where('curriculumBySubject.1.name', 'Mathematics')
            ->where('curriculumBySubject.1.status', 'outline_support_unknown')
            ->where('curriculumBySubject.1.primary_action_label', 'Check outline support')
            ->where('curriculumBySubject.1.primary_action_url', route('academic.sources.show', $source)));
    }

    public function test_private_files_and_draft_routes_deny_students_and_foreign_tenants(): void
    {
        [$ownerA, $tenantA] = $this->adult('owner', 'Academy A');
        [$studentA, $yearA] = $this->enrollment($ownerA, $tenantA);
        $provider = EducationProvider::query()->firstOrFail();
        $subject = Subject::query()->firstOrFail();
        $this->actingIn($ownerA, $tenantA)->post('/learning-plan/curriculum-intake', $this->payload($studentA->id, $yearA->id, $provider->id, $subject->id, ['source_file' => $this->pdf('curriculum.pdf')]));
        $source = AcademicSource::query()->firstOrFail();
        $file = $source->currentFile()->firstOrFail();
        $this->actingIn($ownerA, $tenantA)->get("/academic-setup/sources/{$source->id}/files/{$file->id}/view")->assertOk();
        $this->actingIn($ownerA, $tenantA)->get("/academic-setup/sources/{$source->id}/files/{$file->id}/download")->assertOk();

        $studentUser = User::factory()->create(['email' => null, 'username' => 'portal.student']);
        TenantMembership::create(['tenant_id' => $tenantA->id, 'user_id' => $studentUser->id, 'role' => 'student', 'status' => 'active']);
        $studentA->update(['user_id' => $studentUser->id, 'student_access_enabled_at' => now()]);
        $this->actingIn($studentUser, $tenantA)->get('/learning-plan/curriculum-intake')->assertForbidden();
        $this->actingIn($studentUser, $tenantA)->get("/academic-setup/sources/{$source->id}/files/{$file->id}/view")->assertForbidden();
        $this->actingIn($studentUser, $tenantA)->post("/learning-plan/curriculum-intake/sources/{$source->id}/draft")->assertForbidden();

        [$ownerB, $tenantB] = $this->adult('owner', 'Academy B');
        $this->actingIn($ownerB, $tenantB)->get("/academic-setup/sources/{$source->id}/files/{$file->id}/download")->assertNotFound();
        $this->actingIn($ownerB, $tenantB)->post("/learning-plan/curriculum-intake/sources/{$source->id}/draft")->assertNotFound();
    }

    public function test_subject_workflow_resolves_import_states_counts_and_deterministic_destinations(): void
    {
        [$owner, $tenant] = $this->adult();
        [$student, $year, $grade] = $this->enrollment($owner, $tenant);
        $provider = EducationProvider::query()->where('short_name', 'CFISD')->firstOrFail();
        $framework = StandardsFramework::query()->firstOrFail();
        $math = Subject::query()->where('code', 'MATH')->firstOrFail();
        $this->actingIn($owner, $tenant)->post('/learning-plan/curriculum-intake', $this->payload($student->id, $year->id, $provider->id, $math->id, [
            'source_file' => $this->pdf('workflow-math.pdf'),
        ]));
        $source = AcademicSource::query()->firstOrFail();
        $source->update(['review_status' => 'reviewed']);
        $course = Course::create([
            'subject_id' => $math->id, 'standards_framework_id' => $framework->id,
            'education_provider_id' => $provider->id, 'name' => 'Grade 5 Mathematics', 'code' => 'MATH-5',
            'minimum_grade_level_id' => $grade->id, 'maximum_grade_level_id' => $grade->id, 'status' => 'draft',
        ]);
        $package = CurriculumPackage::create([
            'education_provider_id' => $provider->id, 'standards_framework_id' => $framework->id,
            'name' => 'Grade 5 Curriculum', 'version_label' => '2026-2027', 'status' => 'draft',
        ]);
        $mapping = $package->courseMappings()->create(['course_id' => $course->id, 'grade_level_id' => $grade->id, 'sort_order' => 1, 'required' => true]);
        $import = CurriculumImport::create([
            'academic_source_id' => $source->id, 'academic_source_file_id' => $source->currentFile->id,
            'curriculum_package_id' => $package->id, 'curriculum_package_course_id' => $mapping->id,
            'subject_id' => $math->id, 'grade_level_id' => $grade->id, 'school_year_id' => $year->id,
            'standards_framework_id' => $framework->id, 'created_by_user_id' => $owner->id,
            'status' => 'processing', 'parser_key' => 'test', 'parser_version' => 'test-v1',
        ]);
        $overview = route('workspace.curriculum-intake', ['student_id' => $student->id, 'school_year_id' => $year->id]);

        $this->actingIn($owner, $tenant)->get($overview)->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.workflow_state', 'outline_processing')
            ->where('subjects.1.status_label', 'Curriculum outline processing')
            ->where('subjects.1.primary_action_label', 'View import status')
            ->where('subjects.1.primary_action_url', route('academic.curriculum-imports.show', $import)));
        $import->update(['status' => 'failed']);
        $this->actingIn($owner, $tenant)->get($overview)->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.workflow_state', 'outline_needs_attention')
            ->where('subjects.1.primary_action_label', 'Review import issue'));
        $import->update(['status' => 'review']);
        $this->actingIn($owner, $tenant)->get('/learning-plan?student_id='.$student->id)->assertInertia(fn (Assert $page) => $page
            ->where('curriculumBySubject.1.workflow_state', 'outline_review')
            ->where('curriculumBySubject.1.status_label', 'Curriculum outline ready for review')
            ->where('curriculumBySubject.1.primary_action_label', 'Review curriculum outline')
            ->where('curriculumBySubject.1.primary_action_url', route('academic.curriculum-imports.show', $import)));

        $newerPackage = CurriculumPackage::create([
            'education_provider_id' => $provider->id, 'standards_framework_id' => $framework->id,
            'name' => 'Alternate Math Curriculum', 'version_label' => '2026-2027', 'status' => 'draft',
        ]);
        $newerMapping = $newerPackage->courseMappings()->create(['course_id' => $course->id, 'grade_level_id' => $grade->id, 'sort_order' => 1, 'required' => true]);
        $newerImport = CurriculumImport::create([
            'academic_source_id' => $source->id, 'academic_source_file_id' => $source->currentFile->id,
            'curriculum_package_id' => $newerPackage->id, 'curriculum_package_course_id' => $newerMapping->id,
            'subject_id' => $math->id, 'grade_level_id' => $grade->id, 'school_year_id' => $year->id,
            'standards_framework_id' => $framework->id, 'created_by_user_id' => $owner->id,
            'status' => 'failed', 'parser_key' => 'test', 'parser_version' => 'test-v2',
        ]);
        $this->actingIn($owner, $tenant)->get($overview)->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.curriculum_import_id', $newerImport->id)
            ->where('subjects.1.workflow_state', 'outline_needs_attention'));
        $newerImport->update(['status' => 'superseded']);
        $this->actingIn($owner, $tenant)->get($overview)->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.curriculum_import_id', $import->id)
            ->where('subjects.1.workflow_state', 'outline_review'));

        $periodProposal = $import->proposals()->create(['proposal_type' => 'period', 'included' => true, 'sequence' => 1, 'name' => '1st Nine Weeks', 'reporting_period' => '1st Nine Weeks']);
        $unitProposal = $import->proposals()->create(['parent_proposal_id' => $periodProposal->id, 'proposal_type' => 'unit', 'included' => true, 'sequence' => 1, 'name' => 'Whole Numbers', 'unit_type' => 'instructional', 'standard_codes' => ['5.2A']]);
        $provenance = ['academic_source_id' => $source->id, 'academic_source_file_id' => $source->currentFile->id, 'curriculum_import_id' => $import->id, 'parser_key' => 'test', 'parser_version' => 'test-v1'];
        $period = CurriculumPeriod::create(['curriculum_package_course_id' => $mapping->id, 'name' => '1st Nine Weeks', 'sequence' => 1, 'period_type' => 'reporting_period', 'status' => 'draft', 'curriculum_import_proposal_id' => $periodProposal->id, ...$provenance]);
        $unit = CurriculumUnit::create(['curriculum_period_id' => $period->id, 'curriculum_package_course_id' => $mapping->id, 'name' => 'Whole Numbers', 'sequence' => 1, 'unit_type' => 'instructional', 'included' => true, 'curriculum_import_proposal_id' => $unitProposal->id, ...$provenance]);
        CurriculumUnitStandardAlignment::create(['curriculum_unit_id' => $unit->id, 'standards_framework_id' => $framework->id, 'standard_code' => '5.2A', 'normalized_code' => '5.2A', 'curriculum_import_proposal_id' => $unitProposal->id, ...$provenance]);
        $import->update(['status' => 'approved', 'approved_by_user_id' => $owner->id, 'approved_at' => now()]);
        $this->actingIn($owner, $tenant)->get($overview)->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.workflow_state', 'outline_approved')
            ->where('subjects.1.status_label', 'Curriculum outline approved')
            ->where('subjects.1.primary_action_label', 'View curriculum outline')
            ->where('subjects.1.primary_action_url', route('academic.curriculum.show', $package))
            ->where('subjects.1.period_count', 1)
            ->where('subjects.1.unit_count', 1)
            ->where('subjects.1.standard_alignment_count', 1));

        $enrollment = StudentEnrollment::query()->where('student_id', $student->id)->where('school_year_id', $year->id)->firstOrFail();
        $sourceCount = AcademicSource::query()->count();
        $importCount = CurriculumImport::query()->count();
        $this->actingIn($owner, $tenant)->patch(route('workspace.learning-plan.subjects.hide', [$enrollment, $math]))->assertRedirect();
        $this->actingIn($owner, $tenant)->get($overview)->assertInertia(fn (Assert $page) => $page
            ->has('subjects', 11)->where('subjects', fn ($subjects) => $subjects->doesntContain('code', 'MATH'))
            ->where('hiddenSubjectCount', 1)->where('hiddenSubjects.0.code', 'MATH')
            ->where('hiddenSubjects.0.workflow_state', 'outline_approved'));
        $this->actingIn($owner, $tenant)->get('/learning-plan?student_id='.$student->id)->assertInertia(fn (Assert $page) => $page
            ->where('curriculumBySubject', fn ($subjects) => $subjects->doesntContain('code', 'MATH'))
            ->where('hiddenCurriculumSubjectCount', 1)
            ->where('hiddenCurriculumSubjects.0.status_label', 'Curriculum outline approved'));
        $this->actingIn($owner, $tenant)->get(route('academic.sources.show', $source))->assertOk();
        $this->actingIn($owner, $tenant)->get(route('academic.curriculum-imports.show', $import))->assertOk();
        $this->assertSame($sourceCount, AcademicSource::query()->count());
        $this->assertSame($importCount, CurriculumImport::query()->count());
        $this->assertDatabaseCount('curriculum_periods', 1); $this->assertDatabaseCount('curriculum_units', 1);

        $this->actingIn($owner, $tenant)->patch(route('workspace.learning-plan.subjects.show', [$enrollment, $math]))->assertRedirect();
        $this->actingIn($owner, $tenant)->get($overview)->assertInertia(fn (Assert $page) => $page
            ->where('hiddenSubjectCount', 0)->where('subjects.1.code', 'MATH')
            ->where('subjects.1.workflow_state', 'outline_approved'));

        $newerSource = AcademicSource::create([
            'created_by_user_id' => $owner->id, 'education_provider_id' => $provider->id,
            'school_year_id' => $year->id, 'grade_level_id' => $grade->id,
            'title' => 'Newer unreviewed Math source', 'source_kind' => 'manual',
            'source_category' => 'curriculum', 'authority_level' => 'tenant_created',
            'review_status' => 'unreviewed', 'processing_status' => 'not_requested',
        ]);
        $newerSource->links()->create(['link_type' => 'subject', 'link_id' => $math->id]);
        $this->actingIn($owner, $tenant)->get($overview)->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.source_count', 2)
            ->where('subjects.1.workflow_state', 'outline_approved')
            ->where('subjects.1.source_id', $source->id));
        $source->update(['archived_at' => now()]);
        $this->actingIn($owner, $tenant)->get($overview)->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.workflow_state', 'source_awaiting_review')
            ->where('subjects.1.curriculum_import_id', null));
        $newerSource->update(['archived_at' => now()]);
        $this->actingIn($owner, $tenant)->get($overview)->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.workflow_state', 'not_started')
            ->where('subjects.1.source_count', 0));
    }

    public function test_approved_periodless_custom_curriculum_is_complete_while_empty_approved_imports_remain_integrity_issues(): void
    {
        [$owner, $tenant] = $this->adult();
        [$student, $year, $grade] = $this->enrollment($owner, $tenant);
        $provider = EducationProvider::query()->where('short_name', 'CFISD')->firstOrFail();
        $framework = StandardsFramework::query()->firstOrFail();
        $package = CurriculumPackage::create([
            'education_provider_id' => $provider->id, 'standards_framework_id' => $framework->id,
            'name' => 'Approved Grade 5 Outlines', 'version_label' => '2026-2027', 'status' => 'draft',
        ]);

        foreach (['ELAR', 'MATH', 'SCI'] as $code) {
            $this->createApprovedOutline($owner, $tenant, $student->id, $year->id, $grade->id, $provider->id, $framework->id, $package, $code, 'test-'.$code, 1, false);
        }
        $technologyImport = $this->createApprovedOutline(
            $owner, $tenant, $student->id, $year->id, $grade->id, $provider->id, $framework->id,
            $package, 'TECH', StructuredCustomCurriculumParser::KEY, 8, true,
        );
        $emptyImport = $this->createApprovedOutline(
            $owner, $tenant, $student->id, $year->id, $grade->id, $provider->id, $framework->id,
            $package, 'ART', StructuredCustomCurriculumParser::KEY, 0, true,
        );

        $subjects = collect(app(CurriculumIntakeService::class)->build($student->id, $year->id)['subjects'])->keyBy('code');
        foreach (['ELAR', 'MATH', 'SCI'] as $code) {
            $this->assertSame('outline_approved', $subjects[$code]['workflow_state']);
            $this->assertSame('Curriculum outline approved', $subjects[$code]['status_label']);
        }
        $technology = $subjects['TECH'];
        $this->assertSame('outline_approved', $technology['workflow_state']);
        $this->assertSame('Curriculum outline approved', $technology['status_label']);
        $this->assertNotSame('outline_needs_attention', $technology['workflow_state']);
        $this->assertSame('View curriculum outline', $technology['primary_action_label']);
        $this->assertSame(route('academic.curriculum.show', $package), $technology['primary_action_url']);
        $this->assertSame(0, $technology['period_count']);
        $this->assertSame(8, $technology['unit_count']);
        $this->assertSame($technologyImport->id, $technology['curriculum_import_id']);
        $this->assertSame('outline_needs_attention', $subjects['ART']['workflow_state']);
        $this->assertSame('Review import issue', $subjects['ART']['primary_action_label']);
        $this->assertSame($emptyImport->id, $subjects['ART']['curriculum_import_id']);

        $overview = route('workspace.curriculum-intake', ['student_id' => $student->id, 'school_year_id' => $year->id]);
        $this->actingIn($owner, $tenant)->get($overview)->assertInertia(fn (Assert $page) => $page
            ->where('subjects', fn ($items) => $items->contains(fn ($item) => $item['code'] === 'TECH'
                && $item['status_label'] === 'Curriculum outline approved'
                && $item['primary_action_label'] === 'View curriculum outline'
                && $item['primary_action_url'] === route('academic.curriculum.show', $package)
                && $item['period_count'] === 0 && $item['unit_count'] === 8)));
        $this->actingIn($owner, $tenant)->get('/learning-plan?student_id='.$student->id)->assertInertia(fn (Assert $page) => $page
            ->where('curriculumBySubject', fn ($items) => $items->contains(fn ($item) => $item['code'] === 'TECH'
                && $item['workflow_state'] === 'outline_approved'
                && $item['primary_action_label'] === 'View curriculum outline')));
        $this->actingIn($owner, $tenant)->get(route('academic.curriculum.show', $package))->assertInertia(fn (Assert $page) => $page
            ->has('package.course_mappings', 5)
            ->where('package.course_mappings.3.periodless_curriculum_units.0.name', 'TECH Unit 1')
            ->where('package.course_mappings.3.periodless_curriculum_units.0.metadata.duration_text', '4 weeks')
            ->where('package.course_mappings.3.periodless_curriculum_units.0.components.0.name', 'Anchor Project')
            ->where('package.course_mappings.3.periodless_curriculum_units.0.components.0.descendants.0.name', 'Project Milestone'));
    }

    public function test_learning_plan_curriculum_summary_uses_visible_subject_readiness_instead_of_global_package_selection(): void
    {
        [$owner, $tenant] = $this->adult();
        [$student, $year, $grade] = $this->enrollment($owner, $tenant);
        $provider = EducationProvider::query()->where('short_name', 'CFISD')->firstOrFail();
        $framework = StandardsFramework::query()->firstOrFail();
        $package = CurriculumPackage::create([
            'education_provider_id' => $provider->id, 'standards_framework_id' => $framework->id,
            'name' => 'Subject-owned Grade 5 Outlines', 'version_label' => '2026-2027', 'status' => 'draft',
        ]);
        $approved = collect();
        foreach (['ELAR', 'MATH', 'SCI', 'SS', 'TECH', 'LANG'] as $code) {
            $approved[$code] = $this->createApprovedOutline(
                $owner, $tenant, $student->id, $year->id, $grade->id, $provider->id, $framework->id,
                $package, $code, StructuredCustomCurriculumParser::KEY, 1, true,
            );
        }
        $enrollment = StudentEnrollment::query()->where('student_id', $student->id)->where('school_year_id', $year->id)->firstOrFail();
        $visibleCodes = ['ELAR', 'MATH', 'SCI', 'SS', 'TECH', 'LANG'];
        Subject::query()->whereNotIn('code', $visibleCodes)->get()->each(fn ($subject) => LearningPlanSubjectPreference::create([
            'student_enrollment_id' => $enrollment->id, 'subject_id' => $subject->id, 'is_hidden' => true,
        ]));
        $url = route('workspace.learning-plan', ['student_id' => $student->id]);

        $this->assertNull($year->academicConfiguration);
        $this->actingIn($owner, $tenant)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('selectedStudent.id', $student->id)
            ->where('learningPlan.curriculum', null)
            ->where('learningPlan.curriculum_ready_count', 6)
            ->where('learningPlan.curriculum_total_count', 6)
            ->where('learningPlan.curriculum_status_label', '6 of 6 subjects ready')
            ->where('learningPlan.curriculum_status_detail', 'All active subjects approved')
            ->has('curriculumBySubject', 6)->has('hiddenCurriculumSubjects', 6));

        $approved['SS']->update(['status' => 'review', 'approved_by_user_id' => null, 'approved_at' => null]);
        $year->academicConfiguration()->create([
            'education_provider_id' => $provider->id, 'standards_framework_id' => $framework->id,
            'curriculum_package_id' => $package->id, 'status' => 'active',
        ]);
        $this->actingIn($owner, $tenant)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('learningPlan.curriculum', 'Subject-owned Grade 5 Outlines')
            ->where('learningPlan.curriculum_ready_count', 5)
            ->where('learningPlan.curriculum_total_count', 6)
            ->where('learningPlan.curriculum_status_label', '5 of 6 subjects ready')
            ->where('learningPlan.curriculum_status_detail', '1 subject still needs curriculum'));

        $socialStudies = Subject::query()->where('code', 'SS')->firstOrFail();
        $this->actingIn($owner, $tenant)->patch(route('workspace.learning-plan.subjects.hide', [$enrollment, $socialStudies]))->assertRedirect();
        $this->actingIn($owner, $tenant)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('learningPlan.curriculum_ready_count', 5)
            ->where('learningPlan.curriculum_total_count', 5)
            ->where('learningPlan.curriculum_status_label', '5 of 5 subjects ready'));

        $this->actingIn($owner, $tenant)->patch(route('workspace.learning-plan.subjects.show', [$enrollment, $socialStudies]))->assertRedirect();
        $this->actingIn($owner, $tenant)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('learningPlan.curriculum_ready_count', 5)
            ->where('learningPlan.curriculum_total_count', 6)
            ->where('learningPlan.curriculum_status_label', '5 of 6 subjects ready'));

        $approved['SS']->update(['status' => 'approved', 'approved_by_user_id' => $owner->id, 'approved_at' => now()]);
        $this->actingIn($owner, $tenant)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('learningPlan.curriculum_ready_count', 6)
            ->where('learningPlan.curriculum_total_count', 6)
            ->where('learningPlan.curriculum_status_label', '6 of 6 subjects ready'));
    }

    public function test_tenant_subject_visibility_excludes_another_tenants_custom_subject(): void
    {
        [$ownerA, $tenantA] = $this->adult('owner', 'Academy A');
        $this->enrollment($ownerA, $tenantA);
        [$ownerB, $tenantB] = $this->adult('owner', 'Academy B');
        $this->setContext($ownerB, $tenantB);
        Subject::create(['name' => 'Tenant B Robotics', 'code' => 'B-ROBOTICS', 'sort_order' => 50, 'status' => 'active']);

        $this->actingIn($ownerA, $tenantA)->get('/learning-plan/curriculum-intake')->assertInertia(fn (Assert $page) => $page
            ->where('subjects', fn ($subjects) => $subjects->doesntContain('name', 'Tenant B Robotics')));
    }

    public function test_subject_visibility_permissions_tenant_safety_idempotence_and_uniqueness(): void
    {
        [$ownerA, $tenantA] = $this->adult('owner', 'Academy A');
        [$studentA] = $this->enrollment($ownerA, $tenantA);
        $enrollmentA = StudentEnrollment::query()->where('student_id', $studentA->id)->firstOrFail();
        $math = Subject::query()->where('code', 'MATH')->firstOrFail();

        foreach (['administrator', 'teacher', 'parent'] as $role) {
            $adult = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenantA->id, 'user_id' => $adult->id, 'role' => $role, 'status' => 'active']);
            $this->actingIn($adult, $tenantA)->patch(route('workspace.learning-plan.subjects.hide', [$enrollmentA, $math]))->assertRedirect();
            $this->actingIn($adult, $tenantA)->patch(route('workspace.learning-plan.subjects.show', [$enrollmentA, $math]))->assertRedirect();
        }
        $this->actingIn($ownerA, $tenantA)->patch(route('workspace.learning-plan.subjects.hide', [$enrollmentA, $math]))->assertRedirect();
        $this->actingIn($ownerA, $tenantA)->patch(route('workspace.learning-plan.subjects.hide', [$enrollmentA, $math]))->assertRedirect();
        $this->assertSame(1, LearningPlanSubjectPreference::query()->where('student_enrollment_id', $enrollmentA->id)->where('subject_id', $math->id)->count());
        $this->assertTrue(LearningPlanSubjectPreference::query()->firstOrFail()->is_hidden);

        foreach (['tutor', 'student'] as $role) {
            $user = User::factory()->create();
            TenantMembership::create(['tenant_id' => $tenantA->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);
            $this->actingIn($user, $tenantA)->patch(route('workspace.learning-plan.subjects.show', [$enrollmentA, $math]))->assertForbidden();
        }

        [$ownerB, $tenantB] = $this->adult('owner', 'Academy B');
        $this->setContext($ownerB, $tenantB);
        $foreignSubject = Subject::create(['name' => 'Tenant B Robotics', 'code' => 'B-ROBOTICS', 'sort_order' => 50, 'status' => 'active']);
        $this->actingIn($ownerB, $tenantB)->patch('/learning-plan/enrollments/'.$enrollmentA->id.'/subjects/'.$math->id.'/show')->assertNotFound();
        $this->actingIn($ownerA, $tenantA)->patch('/learning-plan/enrollments/'.$enrollmentA->id.'/subjects/'.$foreignSubject->id.'/hide')->assertNotFound();
        $this->setContext($ownerA, $tenantA);
        $this->assertTrue(LearningPlanSubjectPreference::query()->firstOrFail()->is_hidden);

        $this->expectException(QueryException::class);
        DB::table('learning_plan_subject_preferences')->insert([
            'tenant_id' => $tenantA->id, 'student_enrollment_id' => $enrollmentA->id, 'subject_id' => $math->id,
            'is_hidden' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payload(int $studentId, int $yearId, int $providerId, int $subjectId, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $studentId, 'school_year_id' => $yearId,
            'source_origin' => 'provider', 'education_provider_id' => $providerId,
            'subject_id' => $subjectId, 'title' => 'Grade 5 Mathematics Curriculum',
            'source_kind' => 'upload', 'source_file' => null, 'source_url' => null,
            'manual_reference' => null, 'version_label' => '2026-2027',
        ], $overrides);
    }

    private function subjectPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Grade 5 Mathematics Curriculum', 'source_kind' => 'upload',
            'source_file' => null, 'source_url' => null, 'manual_reference' => null,
            'version_label' => '2026-2027',
        ], $overrides);
    }

    private function subjectCreateUrl(int $studentId, int $yearId, int $subjectId): string
    {
        return $this->subjectStoreUrl($studentId, $yearId, $subjectId).'/add';
    }

    private function createApprovedOutline(
        User $owner,
        Tenant $tenant,
        int $studentId,
        int $yearId,
        int $gradeId,
        int $providerId,
        int $frameworkId,
        CurriculumPackage $package,
        string $subjectCode,
        string $parserKey,
        int $unitCount,
        bool $periodless,
    ): CurriculumImport {
        $subject = Subject::query()->where('code', $subjectCode)->firstOrFail();
        $this->actingIn($owner, $tenant)->post($this->subjectStoreUrl($studentId, $yearId, $subject->id), $this->subjectPayload([
            'title' => $subjectCode.' curriculum', 'source_file' => $this->pdf(strtolower($subjectCode).'-curriculum.pdf'),
        ]))->assertSessionHasNoErrors();
        $source = AcademicSource::query()->where('title', $subjectCode.' curriculum')->firstOrFail();
        $source->update(['review_status' => 'reviewed']);
        $course = Course::create([
            'subject_id' => $subject->id, 'standards_framework_id' => $frameworkId,
            'education_provider_id' => $providerId, 'name' => $subject->name.' Grade 5', 'code' => $subjectCode.'-5-CARD',
            'minimum_grade_level_id' => $gradeId, 'maximum_grade_level_id' => $gradeId, 'status' => 'draft',
        ]);
        $mapping = $package->courseMappings()->create([
            'course_id' => $course->id, 'grade_level_id' => $gradeId,
            'sort_order' => $package->courseMappings()->count(), 'required' => true,
        ]);
        $import = CurriculumImport::create([
            'academic_source_id' => $source->id, 'academic_source_file_id' => $source->currentFile->id,
            'curriculum_package_id' => $package->id, 'curriculum_package_course_id' => $mapping->id,
            'subject_id' => $subject->id, 'grade_level_id' => $gradeId, 'school_year_id' => $yearId,
            'standards_framework_id' => $frameworkId, 'created_by_user_id' => $owner->id,
            'approved_by_user_id' => $owner->id, 'status' => 'approved', 'parser_key' => $parserKey,
            'parser_version' => 'card-state-v1', 'approved_at' => now(),
        ]);
        $period = null;
        if (! $periodless && $unitCount > 0) {
            $proposal = $import->proposals()->create([
                'proposal_type' => 'period', 'included' => true, 'sequence' => 1,
                'name' => 'Reporting Period 1', 'reporting_period' => 'Reporting Period 1',
            ]);
            $period = CurriculumPeriod::create([
                'curriculum_package_course_id' => $mapping->id, 'name' => 'Reporting Period 1',
                'sequence' => 1, 'period_type' => 'reporting_period', 'status' => 'draft',
                'academic_source_id' => $source->id, 'academic_source_file_id' => $source->currentFile->id,
                'curriculum_import_id' => $import->id, 'curriculum_import_proposal_id' => $proposal->id,
                'parser_key' => $parserKey, 'parser_version' => 'card-state-v1',
            ]);
        }
        for ($sequence = 1; $sequence <= $unitCount; $sequence++) {
            $proposal = $import->proposals()->create([
                'proposal_type' => 'unit', 'included' => true, 'sequence' => $sequence,
                'name' => $subjectCode.' Unit '.$sequence, 'unit_type' => 'instructional',
                'parser_metadata' => $periodless ? ['duration_text' => '4 weeks', 'duration_origin' => 'source'] : null,
            ]);
            $unit = CurriculumUnit::create([
                'curriculum_period_id' => $period?->id, 'curriculum_package_course_id' => $mapping->id,
                'name' => $subjectCode.' Unit '.$sequence, 'summary' => 'Unit summary '.$sequence,
                'sequence' => $sequence, 'unit_type' => 'instructional', 'included' => true,
                'metadata' => $proposal->parser_metadata, 'academic_source_id' => $source->id,
                'academic_source_file_id' => $source->currentFile->id, 'curriculum_import_id' => $import->id,
                'curriculum_import_proposal_id' => $proposal->id, 'parser_key' => $parserKey,
                'parser_version' => 'card-state-v1',
            ]);
            if ($periodless && $sequence === 1) {
                $projectProposal = $import->proposals()->create([
                    'parent_proposal_id' => $proposal->id, 'proposal_type' => 'component',
                    'included' => true, 'sequence' => 1, 'name' => 'Anchor Project', 'component_type' => 'project',
                ]);
                $project = CurriculumUnitComponent::create([
                    'curriculum_unit_id' => $unit->id, 'component_type' => 'project', 'name' => 'Anchor Project',
                    'description' => 'Build and demonstrate the unit project.', 'sequence' => 1,
                    'academic_source_id' => $source->id, 'academic_source_file_id' => $source->currentFile->id,
                    'curriculum_import_id' => $import->id, 'curriculum_import_proposal_id' => $projectProposal->id,
                    'parser_key' => $parserKey, 'parser_version' => 'card-state-v1',
                ]);
                $milestoneProposal = $import->proposals()->create([
                    'parent_proposal_id' => $projectProposal->id, 'proposal_type' => 'component',
                    'included' => true, 'sequence' => 1, 'name' => 'Project Milestone', 'component_type' => 'project_milestone',
                ]);
                CurriculumUnitComponent::create([
                    'curriculum_unit_id' => $unit->id, 'parent_component_id' => $project->id,
                    'component_type' => 'project_milestone', 'name' => 'Project Milestone', 'sequence' => 1,
                    'academic_source_id' => $source->id, 'academic_source_file_id' => $source->currentFile->id,
                    'curriculum_import_id' => $import->id, 'curriculum_import_proposal_id' => $milestoneProposal->id,
                    'parser_key' => $parserKey, 'parser_version' => 'card-state-v1',
                ]);
            }
        }

        return $import;
    }

    private function subjectStoreUrl(int $studentId, int $yearId, int $subjectId): string
    {
        return "/learning-plan/curriculum-intake/students/{$studentId}/school-years/{$yearId}/subjects/{$subjectId}";
    }

    private function enrollment(User $user, Tenant $tenant, string $name = 'Kai Singh'): array
    {
        $this->setContext($user, $tenant);
        [$first, $last] = explode(' ', $name, 2);
        $student = $tenant->students()->create(['first_name' => $first, 'last_name' => $last, 'status' => 'active']);
        $year = $tenant->schoolYears()->create(['name' => '2026-2027', 'start_date' => '2026-08-12', 'end_date' => '2027-05-27', 'timezone' => 'America/Chicago', 'status' => 'active']);
        $grade = GradeLevel::query()->where('code', 'G5')->firstOrFail();
        $tenant->enrollments()->create(['student_id' => $student->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'enrollment_date' => '2026-08-12', 'status' => 'active']);

        return [$student, $year, $grade];
    }

    private function adult(string $role = 'owner', string $name = 'Cosmic Quest Academy'): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => $name, 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    }

    private function setContext(User $user, Tenant $tenant): void
    {
        $membership = TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);
    }

    private function pdf(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'curriculum-pdf-');
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
