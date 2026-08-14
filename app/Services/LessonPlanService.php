<?php

namespace App\Services;

use App\Models\CurriculumImport;
use App\Models\CurriculumPackageCourse;
use App\Models\CurriculumUnit;
use App\Models\CurriculumUnitComponent;
use App\Models\CurriculumUnitStandardAlignment;
use App\Models\Lesson;
use App\Models\LessonExperience;
use App\Models\LessonPlan;
use App\Models\StudentActivityResponse;
use App\Models\StudentLessonProgress;
use App\Models\StudentEnrollment;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LessonPlanService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly LessonReadinessService $readiness,
    ) {}

    public function createDraft(StudentEnrollment $enrollment, CurriculumImport $import): LessonPlan
    {
        return DB::transaction(function () use ($enrollment, $import): LessonPlan {
            [$enrollment, $import, $mapping] = $this->lockAndValidateContext($enrollment, $import);
            $latest = LessonPlan::query()->where('student_enrollment_id', $enrollment->id)
                ->where('curriculum_import_id', $import->id)->lockForUpdate()->latest('revision')->first();

            if ($latest && $latest->status !== 'approved') {
                return $latest;
            }

            $plan = LessonPlan::create([
                'student_enrollment_id' => $enrollment->id,
                'curriculum_import_id' => $import->id,
                'curriculum_package_course_id' => $mapping->id,
                'status' => 'draft',
                'revision' => ($latest?->revision ?? 0) + 1,
                'created_by_user_id' => auth()->id(),
            ]);
            if ($latest) {
                $latest->update(['superseded_by_lesson_plan_id' => $plan->id]);
            }
            $this->audit->record('lesson-plan.created', $plan, [], $plan->toArray());

            return $plan;
        });
    }

    public function beginGeneration(
        StudentEnrollment $enrollment,
        CurriculumImport $import,
        string $generatorKey,
        string $generatorVersion,
        array $metadata = [],
    ): LessonPlan {
        $plan = $this->createDraft($enrollment, $import);
        if ($plan->status === 'approved') {
            throw ValidationException::withMessages(['lesson_plan' => 'Approved lesson plans cannot be regenerated in place.']);
        }

        return $this->transition($plan, 'generating', [
            'generator_key' => $generatorKey,
            'generator_version' => $generatorVersion,
            'generation_metadata' => $metadata ?: null,
            'failure_diagnostic' => null,
        ]);
    }

    public function completeGeneration(LessonPlan $plan): LessonPlan
    {
        return $this->transition($plan, 'draft', ['generated_at' => now(), 'failure_diagnostic' => null]);
    }

    public function failGeneration(LessonPlan $plan, string $diagnostic): LessonPlan
    {
        return $this->transition($plan, 'failed', ['failure_diagnostic' => $diagnostic]);
    }

    public function markReviewed(LessonPlan $plan): LessonPlan
    {
        if (! $plan->lessons()->exists() || $plan->lessons()->whereNotIn('status', ['reviewed', 'approved'])->exists()) {
            throw ValidationException::withMessages(['lesson_plan' => 'Review every lesson before marking the lesson plan reviewed.']);
        }

        return $this->transition($plan, 'reviewed', ['reviewed_at' => now(), 'reviewed_by_user_id' => auth()->id()]);
    }

    public function approve(LessonPlan $plan): LessonPlan
    {
        if (! $plan->lessons()->exists() || $plan->lessons()->where('status', '!=', 'approved')->exists()) {
            throw ValidationException::withMessages(['lesson_plan' => 'Approve every lesson before approving the lesson plan.']);
        }

        return $this->transition($plan, 'approved', ['approved_at' => now(), 'approved_by_user_id' => auth()->id()]);
    }

    public function markEdited(LessonPlan $plan): LessonPlan
    {
        if ($plan->status === 'approved') {
            throw ValidationException::withMessages(['lesson_plan' => 'Approved lesson plans are immutable. Create a new revision instead.']);
        }
        if ($plan->status !== 'reviewed') {
            return $plan;
        }

        return $this->transition($plan, 'draft', [
            'reviewed_at' => null, 'reviewed_by_user_id' => null,
        ]);
    }

    public function assertLessonProvenance(LessonPlan $plan, Lesson $lesson): void
    {
        $plan->loadMissing('curriculumImport');
        if ($lesson->lesson_plan_id !== $plan->id
            || $lesson->curriculumUnit()->where('curriculum_import_id', $plan->curriculum_import_id)
                ->where('curriculum_package_course_id', $plan->curriculum_package_course_id)->doesntExist()) {
            throw ValidationException::withMessages(['lesson' => 'The lesson curriculum unit does not belong to this approved lesson-plan context.']);
        }
    }

    public function assertGenerationContext(LessonPlan $plan, CurriculumUnit $unit): void
    {
        [$enrollment, $import, $mapping] = $this->lockAndValidateContext(
            $plan->enrollment()->firstOrFail(),
            $plan->curriculumImport()->firstOrFail(),
        );
        if ($plan->tenant_id !== app(TenantContext::class)->tenantId()
            || $plan->student_enrollment_id !== $enrollment->id
            || $plan->curriculum_import_id !== $import->id
            || $plan->curriculum_package_course_id !== $mapping->id
            || $unit->curriculum_import_id !== $import->id
            || $unit->curriculum_package_course_id !== $mapping->id
            || ! $unit->included) {
            throw ValidationException::withMessages(['generation' => 'The selected unit does not belong to this approved lesson-plan context.']);
        }
    }

    public function createLesson(LessonPlan $plan, CurriculumUnit $unit, array $attributes): Lesson
    {
        if ($plan->status === 'approved') {
            throw ValidationException::withMessages(['lesson_plan' => 'Approved lesson plans are immutable. Create a new revision instead.']);
        }
        $mode = $attributes['lesson_mode'] ?? 'full';
        if (! in_array($mode, Lesson::MODES, true)) {
            throw ValidationException::withMessages(['lesson_mode' => 'Choose a supported lesson mode.']);
        }

        return DB::transaction(function () use ($plan, $unit, $attributes, $mode): Lesson {
            $lesson = $plan->lessons()->create([...$attributes, 'curriculum_unit_id' => $unit->id, 'lesson_mode' => $mode]);
            $this->assertLessonProvenance($plan, $lesson);
            $this->audit->record('lesson.created', $lesson, [], $lesson->toArray());

            return $lesson;
        });
    }

    /** @param array<int, array{role?: string|null, sequence?: int}> $componentLinks */
    public function syncLessonProvenance(Lesson $lesson, array $componentLinks, array $alignmentIds): Lesson
    {
        $plan = $lesson->lessonPlan()->firstOrFail();
        $this->assertLessonProvenance($plan, $lesson);
        if ($plan->status === 'approved' || $lesson->status === 'approved') {
            throw ValidationException::withMessages(['lesson' => 'Lessons in an approved plan are immutable.']);
        }
        $componentIds = array_map('intval', array_keys($componentLinks));
        $alignmentIds = array_values(array_unique(array_map('intval', $alignmentIds)));
        $validComponents = CurriculumUnitComponent::query()->where('curriculum_unit_id', $lesson->curriculum_unit_id)
            ->where('curriculum_import_id', $plan->curriculum_import_id)->whereIn('id', $componentIds)->count();
        $validAlignments = CurriculumUnitStandardAlignment::query()->where('curriculum_unit_id', $lesson->curriculum_unit_id)
            ->where('curriculum_import_id', $plan->curriculum_import_id)->whereIn('id', $alignmentIds)->count();
        if ($validComponents !== count($componentIds) || $validAlignments !== count($alignmentIds)) {
            throw ValidationException::withMessages(['lesson' => 'Lesson curriculum links must belong to its approved curriculum unit.']);
        }
        $tenantId = $lesson->tenant_id;
        $lesson->curriculumComponents()->sync(collect($componentLinks)->mapWithKeys(fn ($link, $id) => [(int) $id => [
            'tenant_id' => $tenantId, 'role' => $link['role'] ?? null, 'sequence' => $link['sequence'] ?? 0,
        ]])->all());
        $lesson->standardAlignments()->sync(collect($alignmentIds)->mapWithKeys(fn ($id) => [$id => ['tenant_id' => $tenantId]])->all());

        return $lesson->fresh(['curriculumComponents', 'standardAlignments']);
    }

    public function markLessonReviewed(Lesson $lesson): Lesson
    {
        return $this->transitionLesson($lesson, 'reviewed');
    }

    public function reviewForStudent(Lesson $lesson): Lesson
    {
        $result = $this->readiness->check($lesson);
        if (! $result['ready']) {
            throw ValidationException::withMessages(['lesson' => $result['blockers']]);
        }
        if ($lesson->status !== 'draft') {
            throw ValidationException::withMessages([
                'lesson' => "Only a draft lesson can be marked reviewed; this lesson is {$lesson->status}.",
            ]);
        }

        return $this->transitionLesson($lesson, 'reviewed');
    }

    public function approveLesson(Lesson $lesson): Lesson
    {
        return $this->transitionLesson($lesson, 'approved', [
            'approved_at' => now(),
            'approved_by_user_id' => auth()->id(),
        ]);
    }

    public function approveForStudent(Lesson $lesson): Lesson
    {
        $result = $this->readiness->check($lesson);
        if (! $result['ready']) {
            throw ValidationException::withMessages(['lesson' => $result['blockers']]);
        }

        return DB::transaction(function () use ($lesson): Lesson {
            $locked = Lesson::query()->whereKey($lesson->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'approved') {
                if ($locked->status !== 'reviewed') {
                    throw ValidationException::withMessages(['lesson' => 'Mark this individual lesson reviewed before approving it for the student.']);
                }
                $locked = $this->transitionLesson($locked, 'approved', [
                    'approved_at' => now(),
                    'approved_by_user_id' => auth()->id(),
                ]);
            }

            $experience = $locked->experience()->lockForUpdate()->firstOrFail();
            if ($experience->status === 'preview') {
                $before = $experience->toArray();
                $experience->update(['status' => 'available']);
                $this->audit->record('lesson-experience.released', $experience, $before, $experience->fresh()->toArray());
            }

            return $locked->fresh(['experience']);
        });
    }

    /**
     * Withdraw an unused individually approved lesson so its current preview can be refined.
     * This intentionally uses guarded query updates after all usage checks pass because the
     * model-level immutability hooks correctly reject ordinary approved-content edits.
     */
    public function reopenUnusedLessonForRefinement(Lesson $lesson): Lesson
    {
        return DB::transaction(function () use ($lesson): Lesson {
            $locked = Lesson::query()->whereKey($lesson->id)->lockForUpdate()->firstOrFail();
            $plan = $locked->lessonPlan()->lockForUpdate()->firstOrFail();
            $experience = $locked->experience()->lockForUpdate()->firstOrFail();

            if ($plan->status !== 'draft') {
                throw ValidationException::withMessages(['lesson' => 'Only a lesson in a draft lesson plan can be reopened for refinement.']);
            }
            if ($locked->status !== 'approved' || $experience->status !== 'available') {
                throw ValidationException::withMessages(['lesson' => 'Only an approved, available lesson can be reopened for refinement.']);
            }

            $progressIds = StudentLessonProgress::query()
                ->where('lesson_experience_id', $experience->id)->lockForUpdate()->pluck('id');
            if (StudentLessonProgress::query()->whereIn('id', $progressIds)->where('is_preview', false)->exists()) {
                throw ValidationException::withMessages(['lesson' => 'The lesson has student-facing progress and cannot be reopened in place.']);
            }
            if (StudentActivityResponse::query()->whereIn('student_lesson_progress_id', $progressIds)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['lesson' => 'The lesson has saved student or preview responses and cannot be reopened in place.']);
            }

            $lessonBefore = $locked->toArray();
            Lesson::query()->whereKey($locked->id)->update([
                'status' => 'draft',
                'approved_at' => null,
                'approved_by_user_id' => null,
                'updated_at' => now(),
            ]);
            $reopened = Lesson::query()->whereKey($locked->id)->firstOrFail();
            $this->audit->record('lesson.reopened-for-refinement', $reopened, $lessonBefore, $reopened->toArray());

            $experienceBefore = $experience->toArray();
            LessonExperience::query()->whereKey($experience->id)->update([
                'status' => 'preview',
                'updated_at' => now(),
            ]);
            $preview = LessonExperience::query()->whereKey($experience->id)->firstOrFail();
            $this->audit->record('lesson-experience.withdrawn-to-preview', $preview, $experienceBefore, $preview->toArray());

            return $reopened->load('experience');
        });
    }

    public function markLessonEdited(Lesson $lesson): Lesson
    {
        if ($lesson->status === 'approved') {
            throw ValidationException::withMessages(['lesson' => 'Approved lessons are immutable. Create a new lesson-plan revision instead.']);
        }
        if ($lesson->status !== 'reviewed') {
            return $lesson;
        }

        return $this->transitionLesson($lesson, 'draft');
    }

    private function transitionLesson(Lesson $lesson, string $to, array $attributes = []): Lesson
    {
        $allowed = ['draft' => ['reviewed'], 'reviewed' => ['draft', 'approved'], 'approved' => []];
        if (! in_array($to, $allowed[$lesson->status] ?? [], true)) {
            throw ValidationException::withMessages(['lesson' => "Lesson status cannot change from {$lesson->status} to {$to}."]);
        }
        $plan = $lesson->lessonPlan()->firstOrFail();
        if ($plan->status === 'approved') {
            throw ValidationException::withMessages(['lesson' => 'Lessons in an approved plan are immutable.']);
        }

        $before = $lesson->toArray();
        $lesson->update([...$attributes, 'status' => $to]);
        $this->audit->record("lesson.{$to}", $lesson, $before, $lesson->fresh()->toArray());

        return $lesson->fresh();
    }

    private function transition(LessonPlan $plan, string $to, array $attributes = []): LessonPlan
    {
        $allowed = [
            'draft' => ['generating', 'reviewed'],
            'generating' => ['draft', 'failed'],
            'failed' => ['generating'],
            'reviewed' => ['draft', 'approved'],
            'approved' => [],
        ];
        if (! in_array($to, $allowed[$plan->status] ?? [], true)) {
            throw ValidationException::withMessages([
                'lesson_plan' => "Lesson plan status cannot change from {$plan->status} to {$to}.",
            ]);
        }

        return DB::transaction(function () use ($plan, $to, $attributes): LessonPlan {
            $locked = LessonPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== $plan->status) {
                throw ValidationException::withMessages(['lesson_plan' => 'The lesson plan changed. Reload before continuing.']);
            }
            $before = $locked->toArray();
            $locked->update([...$attributes, 'status' => $to]);
            $this->audit->record("lesson-plan.{$to}", $locked, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    private function lockAndValidateContext(
        StudentEnrollment $enrollment,
        CurriculumImport $import,
    ): array {
        $tenantId = app(TenantContext::class)->tenantId();
        $enrollment = StudentEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
        $import = CurriculumImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
        if ($enrollment->tenant_id !== $tenantId || $import->tenant_id !== $tenantId) {
            throw ValidationException::withMessages(['lesson_plan' => 'The enrollment and curriculum must belong to the active academy.']);
        }
        if ($import->status !== 'approved') {
            throw ValidationException::withMessages(['curriculum_import_id' => 'Only approved curriculum can be used for a lesson plan.']);
        }
        if (! $import->curriculum_package_course_id || ! $import->curriculum_package_id) {
            throw ValidationException::withMessages(['curriculum_import_id' => 'The approved curriculum does not have a course mapping.']);
        }
        if ($enrollment->school_year_id !== $import->school_year_id) {
            throw ValidationException::withMessages(['curriculum_import_id' => 'The enrollment and curriculum school years do not match.']);
        }
        if ($enrollment->grade_level_id !== $import->grade_level_id) {
            throw ValidationException::withMessages(['curriculum_import_id' => 'The enrollment and curriculum grade levels do not match.']);
        }
        if (! $import->units()->where('curriculum_package_course_id', $import->curriculum_package_course_id)->exists()) {
            throw ValidationException::withMessages(['curriculum_import_id' => 'The approved curriculum has no materialized units.']);
        }

        $mapping = CurriculumPackageCourse::query()->with('course')->whereKey($import->curriculum_package_course_id)->firstOrFail();
        if ($mapping->curriculum_package_id !== $import->curriculum_package_id
            || $mapping->course?->subject_id !== $import->subject_id
            || ($mapping->grade_level_id && $mapping->grade_level_id !== $import->grade_level_id)) {
            throw ValidationException::withMessages(['curriculum_import_id' => 'The approved curriculum subject or course mapping is inconsistent.']);
        }

        return [$enrollment, $import, $mapping];
    }
}
