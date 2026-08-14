<?php

namespace App\Http\Controllers;

use App\Models\CurriculumImport;
use App\Models\CurriculumUnit;
use App\Models\LessonPlan;
use App\Models\StudentEnrollment;
use App\Services\LessonPlanService;
use App\Services\CurriculumUnitLessonGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LessonPlanController extends Controller
{
    public function store(
        StudentEnrollment $enrollment,
        CurriculumImport $curriculumImport,
        LessonPlanService $service,
    ): RedirectResponse {
        Gate::authorize('create', LessonPlan::class);
        $plan = $service->createDraft($enrollment, $curriculumImport);

        return redirect()->route('lesson-plans.show', $plan)->with('success', 'Lesson plan created for review.');
    }

    public function show(LessonPlan $lessonPlan): Response
    {
        Gate::authorize('view', $lessonPlan);
        $lessonPlan->load([
            'enrollment.student', 'enrollment.schoolYear', 'enrollment.gradeLevel',
            'curriculumImport.source.currentFile', 'curriculumImport.curriculumPackage',
            'packageCourse.course.subject', 'lessons.curriculumUnit',
            'curriculumImport.units' => fn ($query) => $query->where('included', true)->orderBy('sequence'),
        ])->loadCount('lessons');

        return Inertia::render('LessonPlans/Show', [
            'lessonPlan' => $this->planProps($lessonPlan),
            'canManage' => Gate::allows('update', $lessonPlan),
            'generatorConfigured' => filled(config('lesson-generation.openai.api_key')),
        ]);
    }

    public function generateUnit(
        LessonPlan $lessonPlan,
        CurriculumUnit $unit,
        CurriculumUnitLessonGenerationService $generation,
    ): RedirectResponse {
        Gate::authorize('update', $lessonPlan);
        $generation->generate($lessonPlan, $unit);
        $count = $lessonPlan->lessons()->where('curriculum_unit_id', $unit->id)->count();

        return redirect()->route('lesson-plans.show', $lessonPlan)
            ->with('success', "{$count} draft lessons generated for {$unit->name}.");
    }

    public function review(LessonPlan $lessonPlan, LessonPlanService $service): RedirectResponse
    {
        Gate::authorize('update', $lessonPlan);
        $service->markReviewed($lessonPlan);

        return back()->with('success', 'Lesson plan marked reviewed.');
    }

    public function approve(LessonPlan $lessonPlan, LessonPlanService $service): RedirectResponse
    {
        Gate::authorize('update', $lessonPlan);
        $service->approve($lessonPlan);

        return back()->with('success', 'Lesson plan approved.');
    }

    private function planProps(LessonPlan $plan): array
    {
        $canMarkReviewed = $plan->lessons->isNotEmpty()
            && $plan->lessons->every(fn ($lesson) => in_array($lesson->status, ['reviewed', 'approved'], true));

        return [
            'id' => $plan->id, 'status' => $plan->status, 'revision' => $plan->revision,
            'failure_diagnostic' => $plan->failure_diagnostic,
            'lesson_count' => $plan->lessons_count,
            'review' => [
                'eligible' => $canMarkReviewed,
                'blocker' => $canMarkReviewed || $plan->lessons->isEmpty()
                    ? null
                    : 'All included lessons must be reviewed before the lesson plan can be marked reviewed.',
            ],
            'student' => $plan->enrollment->student->display_name,
            'school_year' => $plan->enrollment->schoolYear->name,
            'grade' => $plan->enrollment->gradeLevel->name,
            'subject' => $plan->packageCourse->course->subject->name,
            'course' => $plan->packageCourse->course->name,
            'curriculum' => [
                'source' => $plan->curriculumImport->source->title,
                'file' => $plan->curriculumImport->source->currentFile?->original_filename,
                'package' => $plan->curriculumImport->curriculumPackage?->name,
                'approved_at' => $plan->curriculumImport->approved_at?->toIso8601String(),
            ],
            'lessons' => $plan->lessons->map(fn ($lesson) => [
                'id' => $lesson->id, 'sequence' => $lesson->sequence, 'title' => $lesson->title,
                'status' => $lesson->status, 'lesson_mode' => $lesson->lesson_mode,
                'estimated_minutes' => $lesson->estimated_minutes,
                'estimated_preparation_minutes' => $lesson->estimated_preparation_minutes,
                'suggested_sessions' => $lesson->suggested_sessions ?? 1,
                'curriculum_unit' => $lesson->curriculumUnit->name,
                'url' => route('lesson-plans.lessons.show', [$plan, $lesson]),
            ])->all(),
            'units' => $plan->curriculumImport->units->map(function ($unit) use ($plan) {
                $lessons = $plan->lessons->where('curriculum_unit_id', $unit->id);

                return [
                    'id' => $unit->id, 'sequence' => $unit->sequence, 'name' => $unit->name,
                    'unit_type' => $unit->unit_type, 'lesson_count' => $lessons->count(),
                    'lesson_status' => $lessons->isEmpty() ? 'not_generated' : 'generated',
                    'generate_url' => route('lesson-plans.units.generate', [$plan, $unit]),
                ];
            })->values()->all(),
        ];
    }
}
