<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonPlan;
use App\Services\LessonPlanService;
use App\Services\LessonReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LessonController extends Controller
{
    public function show(LessonPlan $lessonPlan, Lesson $lesson, LessonReadinessService $readiness): Response
    {
        Gate::authorize('view', $lessonPlan);
        abort_unless($lesson->lesson_plan_id === $lessonPlan->id, 404);
        $lessonPlan->load(['enrollment.student', 'enrollment.schoolYear', 'packageCourse.course.subject']);
        $lesson->load([
            'curriculumUnit.curriculumImport.source.currentFile',
            'curriculumComponents', 'standardAlignments.standard',
            'sections.descendants',
            'experience',
            'resources',
        ]);

        $releaseReadiness = $readiness->check($lesson);

        return Inertia::render('Lessons/Show', [
            'canManage' => Gate::allows('update', $lessonPlan),
            'lessonPlan' => [
                'id' => $lessonPlan->id,
                'student' => $lessonPlan->enrollment->student->display_name,
                'school_year' => $lessonPlan->enrollment->schoolYear->name,
                'subject' => $lessonPlan->packageCourse->course->subject->name,
                'course' => $lessonPlan->packageCourse->course->name,
            ],
            'lesson' => [
                'id' => $lesson->id, 'sequence' => $lesson->sequence, 'title' => $lesson->title,
                'status' => $lesson->status, 'lesson_mode' => $lesson->lesson_mode,
                'estimated_minutes' => $lesson->estimated_minutes,
                'estimated_preparation_minutes' => $lesson->estimated_preparation_minutes,
                'suggested_sessions' => $lesson->suggested_sessions ?? 1,
                'learning_objective' => $lesson->learning_objective,
                'completion_criteria' => $lesson->completion_criteria,
                'resource_complete' => $lesson->resources->where('category', 'lesson_resource')
                    ->filter(fn ($resource) => (bool) data_get($resource->metadata, 'student_experience_required', true))
                    ->every(fn ($resource) => $resource->isAvailable()),
                'curriculum_unit' => $lesson->curriculumUnit->name,
                'components' => $lesson->curriculumComponents->map(fn ($component) => [
                    'type' => $component->component_type, 'name' => $component->name,
                    'description' => $component->description, 'role' => $component->pivot->role,
                ])->all(),
                'standards' => $lesson->standardAlignments->map(fn ($alignment) => [
                    'code' => $alignment->standard_code,
                    'statement' => $alignment->standard?->statement,
                ])->all(),
                'sections' => $lesson->sections->map(fn ($section) => $this->sectionProps($section))->all(),
                'resource_groups' => $this->resourceGroups($lessonPlan, $lesson),
                'provenance' => [
                    'source' => $lesson->curriculumUnit->curriculumImport->source->title,
                    'file' => $lesson->curriculumUnit->curriculumImport->source->currentFile?->original_filename,
                    'unit' => $lesson->curriculumUnit->name,
                    'source_page' => $lesson->curriculumUnit->source_page,
                ],
                'student_experience_preview_url' => $lesson->experience
                    ? route('lesson-plans.lessons.experience-preview', [$lessonPlan, $lesson])
                    : null,
                'release' => [
                    'ready' => $releaseReadiness['ready'],
                    'blockers' => $releaseReadiness['blockers'],
                    'approved_at' => $lesson->approved_at?->toIso8601String(),
                    'review_url' => $lesson->status === 'draft'
                        ? route('lesson-plans.lessons.review', [$lessonPlan, $lesson])
                        : null,
                    'approve_url' => $lesson->status === 'reviewed'
                        ? route('lesson-plans.lessons.approve', [$lessonPlan, $lesson])
                        : null,
                ],
            ],
        ]);
    }

    public function review(LessonPlan $lessonPlan, Lesson $lesson, LessonPlanService $service): RedirectResponse
    {
        Gate::authorize('update', $lessonPlan);
        abort_unless($lesson->lesson_plan_id === $lessonPlan->id, 404);
        $service->reviewForStudent($lesson);

        return back()->with('success', 'Individual lesson marked reviewed.');
    }

    public function approve(LessonPlan $lessonPlan, Lesson $lesson, LessonPlanService $service): RedirectResponse
    {
        Gate::authorize('update', $lessonPlan);
        abort_unless($lesson->lesson_plan_id === $lessonPlan->id, 404);
        $service->approveForStudent($lesson);

        return back()->with('success', 'Lesson approved for the student.');
    }

    private function resourceGroups(LessonPlan $lessonPlan, Lesson $lesson): array
    {
        return $lesson->resources->groupBy('category')->map(fn ($resources) => $resources->map(fn ($resource) => [
            'id' => $resource->id, 'title' => $resource->title, 'description' => $resource->description,
            'resource_type' => $resource->resource_type, 'delivery_type' => $resource->delivery_type,
            'availability_status' => $resource->availability_status,
            'fulfillment_provider' => $resource->fulfillment_provider,
            'source_attribution' => $resource->source_attribution,
            'license_name' => $resource->license_name,
            'student_experience_required' => (bool) data_get($resource->metadata, 'student_experience_required', $resource->category === 'special_material'),
            'optional_teacher_fallback' => (bool) data_get($resource->metadata, 'optional_teacher_fallback', false),
            'url' => $resource->isAvailable() ? route('lesson-plans.lessons.resources.show', [$lessonPlan, $lesson, $resource]) : null,
        ])->values()->all())->all();
    }

    private function sectionProps($section): array
    {
        return [
            'id' => $section->id, 'type' => $section->section_type, 'title' => $section->title,
            'content' => $section->content, 'audience' => $section->audience,
            'estimated_minutes' => $section->estimated_minutes,
            'children' => $section->descendants->map(fn ($child) => $this->sectionProps($child))->all(),
        ];
    }
}
