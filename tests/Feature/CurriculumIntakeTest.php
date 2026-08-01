<?php

namespace Tests\Feature;

use App\Models\AcademicSource;
use App\Models\CurriculumPackage;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\AcademicReferenceSeeder;
use Database\Seeders\GradeLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
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
            ->where('subjects.1.status', 'not_started'));

        $parent = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $parent->id, 'role' => 'parent', 'status' => 'active']);
        $this->actingIn($parent, $tenant)->get('/learning-plan/curriculum-intake')->assertOk();
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
        $this->assertFalse(Schema::hasTable('lessons'));
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
            ->where('subjects.1.status', 'source_added')->where('subjects.1.status_label', 'Source added'));
        $this->actingIn($owner, $tenant)->patch("/academic-setup/sources/{$source->id}/review", ['review_status' => 'in_review'])->assertRedirect();
        $this->actingIn($owner, $tenant)->get('/learning-plan/curriculum-intake')->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.status', 'needs_review'));
        $this->actingIn($owner, $tenant)->patch("/academic-setup/sources/{$source->id}/review", ['review_status' => 'reviewed'])->assertRedirect();
        $this->actingIn($owner, $tenant)->get('/learning-plan/curriculum-intake')->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.status', 'reviewed'));

        $this->actingIn($owner, $tenant)->post("/learning-plan/curriculum-intake/sources/{$source->id}/draft")->assertRedirect();
        $package = CurriculumPackage::query()->firstOrFail();
        $this->actingIn($owner, $tenant)->post("/learning-plan/curriculum-intake/sources/{$source->id}/draft")->assertRedirect(route('academic.curriculum.show', $package));
        $this->assertDatabaseCount('curriculum_packages', 1);
        $this->assertDatabaseCount('curriculum_package_courses', 0);
        $this->assertNull($package->description);
        $this->assertNull($package->standards_framework_id);
        $this->assertDatabaseHas('academic_source_links', ['academic_source_id' => $source->id, 'link_type' => 'curriculum_package', 'link_id' => $package->id]);
        $this->actingIn($owner, $tenant)->get('/learning-plan/curriculum-intake')->assertInertia(fn (Assert $page) => $page
            ->where('subjects.1.status', 'draft_started')->where('subjects.1.status_label', 'Draft curriculum started'));
        $this->actingIn($owner, $tenant)->get('/learning-plan?student_id='.$student->id)->assertInertia(fn (Assert $page) => $page
            ->where('curriculumBySubject.1.name', 'Mathematics')
            ->where('curriculumBySubject.1.status', 'draft_started'));
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
