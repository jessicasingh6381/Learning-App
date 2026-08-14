<?php

namespace Tests\Feature;

use App\Models\AcademicSource;
use App\Models\Course;
use App\Models\CurriculumImport;
use App\Models\CurriculumPackage;
use App\Models\CurriculumUnit;
use App\Models\EducationProvider;
use App\Models\Lesson;
use App\Models\LessonPlan;
use App\Models\SchoolYear;
use App\Models\StandardsFramework;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentLessonProgress;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\LessonExperienceService;
use App\Services\LessonPlanService;
use App\Services\LessonReadinessService;
use App\Tenancy\TenantContext;
use Database\Seeders\AcademicReferenceSeeder;
use Database\Seeders\GradeLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentLessonReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([GradeLevelSeeder::class, AcademicReferenceSeeder::class]);
    }

    public function test_draft_and_preview_only_lessons_are_invisible_and_missing_resources_block_approval(): void
    {
        $context = $this->context();
        $lesson = $this->lesson($context, 1, 'First Lesson');
        $lesson->resources()->update(['availability_status' => 'needs_asset', 'asset_disk' => null, 'asset_path' => null]);

        $this->assertFalse(app(LessonReadinessService::class)->ready($lesson));
        $this->actingIn($context['studentUser'], $context['tenant'])
            ->get(route('student.learning'))
            ->assertInertia(fn (Assert $page) => $page->where('subjects', []));
        $this->actingIn($context['studentUser'], $context['tenant'])
            ->get(route('student.lessons.experience.show', $lesson))->assertNotFound();

        $this->actingIn($context['teacher'], $context['tenant'])
            ->get(route('lesson-plans.lessons.show', [$context['plan'], $lesson]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lesson.release.ready', false)
                ->where('lesson.release.blockers', fn ($blockers) => $blockers->contains('One or more required lesson resources are unresolved.')));
        $this->actingIn($context['teacher'], $context['tenant'])
            ->post(route('lesson-plans.lessons.review', [$context['plan'], $lesson]))
            ->assertSessionHasErrors('lesson');
        $this->actingIn($context['teacher'], $context['tenant'])
            ->post(route('lesson-plans.lessons.approve', [$context['plan'], $lesson]))
            ->assertSessionHasErrors('lesson');
        $this->assertSame('preview', $lesson->experience()->value('status'));
    }

    public function test_ready_lesson_can_be_explicitly_approved_while_plan_stays_draft_and_is_audited(): void
    {
        $context = $this->context();
        $lesson = $this->lesson($context, 1, 'First Lesson');

        $this->actingIn($context['teacher'], $context['tenant'])
            ->get(route('lesson-plans.lessons.show', [$context['plan'], $lesson]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lesson.status', 'draft')
                ->where('lesson.release.ready', true)
                ->where('lesson.release.review_url', route('lesson-plans.lessons.review', [$context['plan'], $lesson]))
                ->where('lesson.release.approve_url', null));
        $this->actingIn($context['teacher'], $context['tenant'])
            ->post(route('lesson-plans.lessons.review', [$context['plan'], $lesson]))
            ->assertRedirect();
        $this->assertSame('reviewed', $lesson->fresh()->status);
        $this->assertSame('draft', $context['plan']->fresh()->status);
        $this->actingIn($context['teacher'], $context['tenant'])
            ->get(route('lesson-plans.lessons.show', [$context['plan'], $lesson]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lesson.status', 'reviewed')
                ->where('lesson.release.review_url', null)
                ->where('lesson.release.approve_url', route('lesson-plans.lessons.approve', [$context['plan'], $lesson])));
        $this->actingIn($context['teacher'], $context['tenant'])
            ->post(route('lesson-plans.lessons.approve', [$context['plan'], $lesson]))
            ->assertRedirect();

        $this->assertSame('approved', $lesson->fresh()->status);
        $this->assertSame($context['teacher']->id, $lesson->fresh()->approved_by_user_id);
        $this->assertNotNull($lesson->fresh()->approved_at);
        $this->assertSame('available', $lesson->experience()->value('status'));
        $this->assertSame('draft', $context['plan']->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lesson.approved', 'user_id' => $context['teacher']->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lesson-experience.released', 'user_id' => $context['teacher']->id]);

        $this->actingIn($context['studentUser'], $context['tenant'])
            ->get(route('student.learning'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('subjects.0.subject', 'Mathematics')
                ->where('subjects.0.lesson.title', 'First Lesson')
                ->where('subjects.0.lesson.action_label', 'Start lesson'));
    }

    public function test_plan_level_review_is_ineligible_and_failed_post_surfaces_validation(): void
    {
        $context = $this->context();
        $this->lesson($context, 1, 'Prepared Lesson');
        $context['plan']->lessons()->create([
            'curriculum_unit_id' => $context['unit']->id,
            'sequence' => 2,
            'title' => 'Future Draft Lesson',
            'status' => 'draft',
        ]);

        $this->actingIn($context['teacher'], $context['tenant'])
            ->get(route('lesson-plans.show', $context['plan']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lessonPlan.status', 'draft')
                ->where('lessonPlan.review.eligible', false)
                ->where('lessonPlan.review.blocker', 'All included lessons must be reviewed before the lesson plan can be marked reviewed.'));

        $this->actingIn($context['teacher'], $context['tenant'])
            ->post(route('lesson-plans.review', $context['plan']))
            ->assertRedirect()
            ->assertSessionHasErrors([
                'lesson_plan' => 'Review every lesson before marking the lesson plan reviewed.',
            ]);
        $this->assertSame('draft', $context['plan']->fresh()->status);
    }

    public function test_sequence_cannot_be_skipped_and_completion_unlocks_the_next_approved_lesson(): void
    {
        $context = $this->context();
        $first = $this->lesson($context, 1, 'First Lesson');
        $second = $this->lesson($context, 2, 'Second Lesson');
        $this->release($first);
        $this->release($second);

        $this->actingIn($context['studentUser'], $context['tenant'])
            ->get(route('student.lessons.experience.show', $second))->assertNotFound();
        $this->actingIn($context['studentUser'], $context['tenant'])
            ->get(route('student.learning'))
            ->assertInertia(fn (Assert $page) => $page->where('subjects.0.lesson.id', $first->id));

        StudentLessonProgress::create([
            'lesson_experience_id' => $first->experience->id,
            'student_enrollment_id' => $context['enrollment']->id,
            'current_activity_id' => $first->experience->activities()->value('id'),
            'is_preview' => false,
            'status' => 'completed',
            'started_at' => now(),
            'last_activity_at' => now(),
            'completed_at' => now(),
        ]);

        $this->actingIn($context['studentUser'], $context['tenant'])
            ->get(route('student.learning'))
            ->assertInertia(fn (Assert $page) => $page->where('subjects.0.lesson.id', $second->id));
        $this->actingIn($context['studentUser'], $context['tenant'])
            ->get(route('student.lessons.experience.show', $second))->assertOk();
    }

    public function test_student_start_resumes_without_duplicates_and_teacher_preview_is_separate(): void
    {
        $context = $this->context();
        $lesson = $this->lesson($context, 1, 'Resume Lesson');
        $preview = app(LessonExperienceService::class)->progress(
            $lesson->experience,
            $context['enrollment'],
            true,
            $context['teacher'],
        );
        $this->release($lesson);

        $this->actingIn($context['studentUser'], $context['tenant'])
            ->get(route('student.lessons.experience.show', $lesson))->assertOk();
        $studentProgress = StudentLessonProgress::query()->where('is_preview', false)->firstOrFail();
        $studentProgress->update(['last_activity_at' => now()->subMinute()]);
        $this->actingIn($context['studentUser'], $context['tenant'])
            ->get(route('student.lessons.experience.show', $lesson))
            ->assertInertia(fn (Assert $page) => $page->where('progress.id', $studentProgress->id));

        $this->assertDatabaseCount('student_lesson_progress', 2);
        $this->assertTrue($preview->is_preview);
        $this->assertFalse($studentProgress->is_preview);
    }

    public function test_other_students_and_tenants_cannot_access_released_lesson(): void
    {
        $context = $this->context();
        $lesson = $this->lesson($context, 1, 'Private Lesson');
        $this->release($lesson);

        $otherUser = User::factory()->create(['must_change_password' => false]);
        TenantMembership::create(['tenant_id' => $context['tenant']->id, 'user_id' => $otherUser->id, 'role' => 'student', 'status' => 'active']);
        $other = $context['tenant']->students()->create(['user_id' => $otherUser->id, 'student_access_enabled_at' => now(), 'first_name' => 'Other', 'last_name' => 'Student', 'status' => 'active']);
        StudentEnrollment::create(['student_id' => $other->id, 'school_year_id' => $context['year']->id, 'grade_level_id' => $context['enrollment']->grade_level_id, 'enrollment_date' => '2026-08-01', 'status' => 'active']);
        $this->actingIn($otherUser, $context['tenant'])
            ->get(route('student.lessons.experience.show', $lesson))->assertNotFound();

        $otherTenant = Tenant::create(['name' => 'Other Academy', 'type' => 'homeschool_family', 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
        $outsider = User::factory()->create(['must_change_password' => false]);
        TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id, 'role' => 'student', 'status' => 'active']);
        $this->setContext($outsider, $otherTenant);
        $otherTenant->students()->create(['user_id' => $outsider->id, 'student_access_enabled_at' => now(), 'first_name' => 'Outside', 'last_name' => 'Student', 'status' => 'active']);
        $this->actingIn($outsider, $otherTenant)
            ->get(route('student.lessons.experience.show', $lesson->id))->assertNotFound();
    }

    public function test_approved_instructional_content_cannot_be_silently_mutated(): void
    {
        $context = $this->context();
        $lesson = $this->lesson($context, 1, 'Immutable Lesson');
        $this->release($lesson);

        try {
            $lesson->fresh()->update(['title' => 'Silent replacement']);
            $this->fail('Approved content should be immutable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lesson', $exception->errors());
        }
        $this->assertSame('Immutable Lesson', $lesson->fresh()->title);

        $this->expectException(ValidationException::class);
        $lesson->sections()->firstOrFail()->update(['content' => 'Silent replacement']);
    }

    public function test_unused_individually_approved_lesson_can_be_audited_and_reopened_for_refinement(): void
    {
        $context = $this->context();
        $lesson = $this->lesson($context, 1, 'Unused Approved Lesson');
        $preview = app(LessonExperienceService::class)->progress(
            $lesson->experience,
            $context['enrollment'],
            true,
            $context['teacher'],
        );
        $this->release($lesson);

        $reopened = app(LessonPlanService::class)->reopenUnusedLessonForRefinement($lesson->fresh());

        $this->assertSame('draft', $reopened->status);
        $this->assertNull($reopened->approved_at);
        $this->assertNull($reopened->approved_by_user_id);
        $this->assertSame('preview', $reopened->experience->status);
        $this->assertSame('draft', $context['plan']->fresh()->status);
        $this->assertDatabaseHas('student_lesson_progress', ['id' => $preview->id, 'is_preview' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lesson.reopened-for-refinement', 'auditable_id' => (string) $lesson->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lesson-experience.withdrawn-to-preview', 'auditable_id' => (string) $lesson->experience->id]);
    }

    public function test_lesson_with_student_facing_progress_cannot_be_reopened_in_place(): void
    {
        $context = $this->context();
        $lesson = $this->lesson($context, 1, 'Started Lesson');
        $this->release($lesson);
        StudentLessonProgress::create([
            'lesson_experience_id' => $lesson->experience->id,
            'student_enrollment_id' => $context['enrollment']->id,
            'current_activity_id' => $lesson->experience->activities()->value('id'),
            'is_preview' => false,
            'status' => 'in_progress',
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        app(LessonPlanService::class)->reopenUnusedLessonForRefinement($lesson->fresh());
    }

    public function test_lesson_with_a_saved_preview_response_cannot_be_reopened_in_place(): void
    {
        $context = $this->context();
        $lesson = $this->lesson($context, 1, 'Responded Preview Lesson');
        $experienceService = app(LessonExperienceService::class);
        $progress = $experienceService->progress($lesson->experience, $context['enrollment'], true, $context['teacher']);
        $experienceService->respond($progress, $lesson->experience->activities->first(), ['acknowledged' => true]);
        $this->release($lesson);

        $this->expectException(ValidationException::class);
        app(LessonPlanService::class)->reopenUnusedLessonForRefinement($lesson->fresh());
    }

    private function lesson(array $context, int $sequence, string $title): Lesson
    {
        $lesson = $context['plan']->lessons()->create([
            'curriculum_unit_id' => $context['unit']->id,
            'sequence' => $sequence,
            'title' => $title,
            'status' => 'draft',
            'learning_objective' => 'Explain the lesson evidence.',
            'completion_criteria' => 'Complete the configured activity.',
            'estimated_minutes' => 30,
        ]);
        $section = $lesson->sections()->create([
            'section_type' => 'instruction', 'sequence' => 1, 'title' => 'Learn',
            'content' => 'Student-safe lesson content.', 'audience' => 'student',
        ]);
        $experience = $lesson->experience()->create([
            'status' => 'preview', 'mission_title' => $title, 'mission_brief' => 'Complete this mission.',
            'completion_title' => 'Complete', 'completion_message' => 'Your work is saved.',
        ]);
        $experience->activities()->create([
            'source_lesson_section_id' => $section->id,
            'sequence' => 1,
            'activity_type' => 'instruction',
            'display_title' => 'Begin',
            'student_instructions' => 'Read and continue.',
            'content' => 'Student-safe activity.',
            'completion_condition' => ['type' => 'acknowledge'],
        ]);
        $lesson->resources()->create([
            'category' => 'lesson_resource', 'resource_type' => 'reference', 'title' => 'Ready resource',
            'delivery_type' => 'viewable', 'availability_status' => 'ready', 'sort_order' => 1,
            'asset_disk' => 'local', 'asset_path' => 'test/ready.pdf',
            'metadata' => ['student_experience_required' => true],
        ]);

        return $lesson->fresh(['experience.activities']);
    }

    private function release(Lesson $lesson): void
    {
        $service = app(LessonPlanService::class);
        $service->reviewForStudent($lesson);
        $service->approveForStudent($lesson->fresh());
    }

    private function context(): array
    {
        $teacher = User::factory()->create();
        $studentUser = User::factory()->create(['must_change_password' => false]);
        $tenant = Tenant::create(['name' => 'Academy', 'type' => 'homeschool_family', 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => 'owner', 'status' => 'active']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $studentUser->id, 'role' => 'student', 'status' => 'active']);
        $this->setContext($teacher, $tenant);
        $grade = \App\Models\GradeLevel::query()->where('code', 'G5')->firstOrFail();
        $student = $tenant->students()->create(['user_id' => $studentUser->id, 'student_access_enabled_at' => now(), 'first_name' => 'Kai', 'last_name' => 'Student', 'status' => 'active']);
        $year = SchoolYear::create(['name' => 'Current Year', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'timezone' => 'UTC', 'status' => 'active']);
        $enrollment = StudentEnrollment::create(['student_id' => $student->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'enrollment_date' => '2026-08-01', 'status' => 'active']);
        $provider = EducationProvider::create(['name' => 'Provider', 'provider_type' => 'publisher', 'status' => 'active']);
        $framework = StandardsFramework::create(['education_provider_id' => $provider->id, 'name' => 'Standards', 'version_label' => '1', 'status' => 'active']);
        $subject = Subject::query()->where('code', 'MATH')->firstOrFail();
        $course = Course::create(['subject_id' => $subject->id, 'standards_framework_id' => $framework->id, 'name' => 'Mathematics', 'code' => 'MATH-5', 'status' => 'draft']);
        $package = CurriculumPackage::create(['education_provider_id' => $provider->id, 'standards_framework_id' => $framework->id, 'name' => 'Curriculum', 'version_label' => '1', 'status' => 'draft']);
        $mapping = $package->courseMappings()->create(['course_id' => $course->id, 'grade_level_id' => $grade->id, 'sort_order' => 1, 'required' => true]);
        $source = AcademicSource::create(['education_provider_id' => $provider->id, 'school_year_id' => $year->id, 'grade_level_id' => $grade->id, 'title' => 'Source', 'source_kind' => 'upload', 'source_category' => 'curriculum', 'authority_level' => 'tenant_created', 'review_status' => 'reviewed', 'processing_status' => 'completed']);
        $file = $source->files()->create(['uploaded_by_user_id' => $teacher->id, 'version_number' => 1, 'current_key' => 'current', 'is_current' => true, 'disk' => 'local', 'stored_path' => 'test/source.pdf', 'stored_filename' => 'source.pdf', 'original_filename' => 'source.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 1, 'checksum_sha256' => str_repeat('a', 64), 'uploaded_at' => now()]);
        $import = CurriculumImport::create(['academic_source_id' => $source->id, 'academic_source_file_id' => $file->id, 'curriculum_package_id' => $package->id, 'curriculum_package_course_id' => $mapping->id, 'subject_id' => $subject->id, 'grade_level_id' => $grade->id, 'school_year_id' => $year->id, 'standards_framework_id' => $framework->id, 'created_by_user_id' => $teacher->id, 'approved_by_user_id' => $teacher->id, 'status' => 'approved', 'parser_key' => 'test', 'parser_version' => '1', 'approved_at' => now()]);
        $unitProposal = $import->proposals()->create(['proposal_type' => 'unit', 'included' => true, 'sequence' => 1, 'name' => 'Unit', 'unit_type' => 'instructional']);
        $unit = CurriculumUnit::create(['curriculum_package_course_id' => $mapping->id, 'name' => 'Unit', 'sequence' => 1, 'unit_type' => 'instructional', 'included' => true, 'academic_source_id' => $source->id, 'academic_source_file_id' => $file->id, 'curriculum_import_id' => $import->id, 'curriculum_import_proposal_id' => $unitProposal->id, 'parser_key' => 'test', 'parser_version' => '1']);
        $plan = LessonPlan::create(['student_enrollment_id' => $enrollment->id, 'curriculum_import_id' => $import->id, 'curriculum_package_course_id' => $mapping->id, 'status' => 'draft', 'revision' => 1, 'created_by_user_id' => $teacher->id]);

        return compact('teacher', 'studentUser', 'tenant', 'year', 'enrollment', 'unit', 'plan');
    }

    private function setContext(User $user, Tenant $tenant): void
    {
        $membership = TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);
        $this->actingAs($user);
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    }
}
