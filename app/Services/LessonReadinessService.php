<?php

namespace App\Services;

use App\Models\Lesson;
use App\Tenancy\TenantContext;

class LessonReadinessService
{
    /** @return array{ready: bool, blockers: list<string>} */
    public function check(Lesson $lesson): array
    {
        $lesson->loadMissing([
            'lessonPlan.enrollment',
            'lessonPlan.curriculumImport',
            'lessonPlan.packageCourse.course',
            'curriculumUnit',
            'experience.activities.sourceSection',
            'resources',
        ]);

        $blockers = [];
        $plan = $lesson->lessonPlan;
        $enrollment = $plan?->enrollment;
        $import = $plan?->curriculumImport;
        $mapping = $plan?->packageCourse;
        $unit = $lesson->curriculumUnit;
        $tenantId = app(TenantContext::class)->tenantId();

        if (! $plan || ! $enrollment || ! $import || ! $mapping || ! $unit) {
            $blockers[] = 'Lesson context is incomplete.';
        } else {
            if (! $tenantId || collect([$lesson, $plan, $enrollment, $import])
                ->contains(fn ($model) => (int) $model->tenant_id !== (int) $tenantId)) {
                $blockers[] = 'Lesson tenant context does not match the active academy.';
            }
            if ($plan->superseded_by_lesson_plan_id) {
                $blockers[] = 'Lesson belongs to a superseded lesson-plan revision.';
            }
            if ($enrollment->status !== 'active'
                || $enrollment->school_year_id !== $import->school_year_id
                || $enrollment->grade_level_id !== $import->grade_level_id) {
                $blockers[] = 'Lesson enrollment or school-year context is not active and aligned.';
            }
            if ($import->status !== 'approved'
                || $plan->curriculum_import_id !== $unit->curriculum_import_id
                || $plan->curriculum_package_course_id !== $unit->curriculum_package_course_id
                || $import->curriculum_package_course_id !== $mapping->id
                || $import->subject_id !== $mapping->course?->subject_id
                || ! $unit->included) {
                $blockers[] = 'Lesson curriculum provenance is not attached to the approved plan context.';
            }
        }

        if (in_array($lesson->status, ['generating', 'failed'], true)
            || in_array($plan?->status, ['generating', 'failed'], true)) {
            $blockers[] = 'Lesson or lesson plan is generating or failed.';
        }

        $experience = $lesson->experience;
        if (! $experience) {
            $blockers[] = 'Student experience has not been built.';
        } elseif (! in_array($experience->status, ['preview', 'available'], true)) {
            $blockers[] = 'Student experience has not passed its preview build state.';
        }

        $activities = $experience?->activities ?? collect();
        if ($activities->isEmpty()) {
            $blockers[] = 'Student experience has no activities.';
        } elseif ($activities->sortBy('sequence')->pluck('sequence')->values()->all() !== range(1, $activities->count())
            || $activities->contains(function ($activity) use ($lesson): bool {
            $source = $activity->sourceSection;

            return blank($activity->display_title)
                || blank($activity->activity_type)
                || ! is_array($activity->completion_condition)
                || $activity->completion_condition === []
                || ! $source
                || $source->lesson_id !== $lesson->id
                || $source->audience === 'teacher';
        })) {
            $blockers[] = 'Student activity configuration is incomplete or references teacher-only content.';
        }

        $requiredResources = $lesson->resources->filter(fn ($resource) =>
            (bool) data_get(
                $resource->metadata,
                'student_experience_required',
                $resource->category === 'lesson_resource' || $resource->category === 'special_material'
            )
        );
        if ($requiredResources->contains(fn ($resource) =>
            $resource->category === 'lesson_resource' ? ! $resource->isAvailable()
                : ! in_array($resource->availability_status, ['ready', 'not_applicable'], true)
        )) {
            $blockers[] = 'One or more required lesson resources are unresolved.';
        }

        return ['ready' => $blockers === [], 'blockers' => array_values(array_unique($blockers))];
    }

    public function ready(Lesson $lesson): bool
    {
        return $this->check($lesson)['ready'];
    }
}
