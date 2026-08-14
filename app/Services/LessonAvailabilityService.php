<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\StudentEnrollment;

class LessonAvailabilityService
{
    public function __construct(private readonly LessonReadinessService $readiness) {}

    public function canAccess(Lesson $lesson, StudentEnrollment $enrollment): bool
    {
        $lesson->loadMissing(['lessonPlan.packageCourse.course', 'experience']);
        $plan = $lesson->lessonPlan;

        if (! $plan
            || $plan->student_enrollment_id !== $enrollment->id
            || $plan->tenant_id !== $enrollment->tenant_id
            || $enrollment->status !== 'active'
            || $plan->superseded_by_lesson_plan_id
            || $lesson->status !== 'approved'
            || $lesson->experience?->status !== 'available'
            || ! $this->readiness->ready($lesson)) {
            return false;
        }

        return ! $plan->lessons()
            ->where('status', 'approved')
            ->where('sequence', '<', $lesson->sequence)
            ->whereHas('experience', fn ($query) => $query->where('status', 'available'))
            ->whereDoesntHave('experience.progresses', fn ($query) => $query
                ->where('student_enrollment_id', $enrollment->id)
                ->where('is_preview', false)
                ->where('status', 'completed'))
            ->exists();
    }

    public function nextForEnrollment(StudentEnrollment $enrollment): array
    {
        $lessons = Lesson::query()
            ->with(['lessonPlan.packageCourse.course.subject', 'experience.progresses' => fn ($query) => $query
                ->where('student_enrollment_id', $enrollment->id)->where('is_preview', false)])
            ->where('status', 'approved')
            ->whereHas('lessonPlan', fn ($query) => $query
                ->where('student_enrollment_id', $enrollment->id)
                ->whereNull('superseded_by_lesson_plan_id'))
            ->whereHas('experience', fn ($query) => $query->where('status', 'available'))
            ->get()
            ->filter(fn ($lesson) => $this->readiness->ready($lesson))
            ->groupBy(fn ($lesson) => $lesson->lessonPlan->packageCourse->course->subject_id);

        return $lessons->map(function ($subjectLessons) use ($enrollment) {
            $ordered = $subjectLessons->sortBy(fn ($lesson) => [$lesson->sequence, $lesson->id])->values();
            $next = $ordered->first(fn ($lesson) => $lesson->experience->progresses->first()?->status !== 'completed');
            if (! $next || ! $this->canAccess($next, $enrollment)) {
                return null;
            }
            $progress = $next->experience->progresses->first();

            return [
                'subject' => $next->lessonPlan->packageCourse->course->subject->name,
                'lesson' => [
                    'id' => $next->id,
                    'sequence' => $next->sequence,
                    'title' => $next->title,
                    'progress_status' => $progress?->status ?? 'not_started',
                    'action_label' => $progress ? 'Continue' : 'Start lesson',
                    'url' => route('student.lessons.experience.show', $next),
                ],
            ];
        })->filter()->sortBy('subject')->values()->all();
    }
}
