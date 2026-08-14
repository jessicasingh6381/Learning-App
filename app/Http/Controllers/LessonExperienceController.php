<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonActivity;
use App\Models\LessonPlan;
use App\Models\StudentLessonProgress;
use App\Services\LessonExperiencePresenter;
use App\Services\LessonExperienceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LessonExperienceController extends Controller
{
    public function preview(LessonPlan $lessonPlan, Lesson $lesson, LessonExperienceService $service, LessonExperiencePresenter $presenter): Response
    {
        Gate::authorize('update', $lessonPlan);
        abort_unless($lesson->lesson_plan_id === $lessonPlan->id, 404);
        $experience = $lesson->experience()->firstOrFail();
        $progress = $service->progress($experience, $lessonPlan->enrollment, true, request()->user());

        return Inertia::render('StudentExperiences/Show', array_merge($presenter->props($progress, true,
            fn ($activity) => route('lesson-plans.lessons.experience-preview.respond', [$lessonPlan, $lesson, $progress, $activity]),
            fn ($activity) => route('lesson-plans.lessons.experience-preview.draft', [$lessonPlan, $lesson, $progress, $activity]),
            fn ($resource) => route('lesson-plans.lessons.resources.show', [$lessonPlan, $lesson, $resource])), [
                'return_url' => route('lesson-plans.lessons.show', [$lessonPlan, $lesson]),
            ]));
    }

    public function respondPreview(Request $request, LessonPlan $lessonPlan, Lesson $lesson, StudentLessonProgress $progress, LessonActivity $activity, LessonExperienceService $service): RedirectResponse
    {
        Gate::authorize('update', $lessonPlan);
        abort_unless($lesson->lesson_plan_id === $lessonPlan->id && $progress->is_preview
            && $progress->previewed_by_user_id === $request->user()->id
            && $progress->student_enrollment_id === $lessonPlan->student_enrollment_id
            && $progress->lesson_experience_id === $lesson->experience()->value('id'), 404);
        $validated = $request->validate(['response' => ['required', 'array']]);
        $service->respond($progress, $activity, $validated['response']);
        return back();
    }

    public function saveDraftPreview(Request $request, LessonPlan $lessonPlan, Lesson $lesson, StudentLessonProgress $progress, LessonActivity $activity, LessonExperienceService $service): JsonResponse
    {
        Gate::authorize('update', $lessonPlan);
        abort_unless($lesson->lesson_plan_id === $lessonPlan->id && $progress->is_preview
            && $progress->previewed_by_user_id === $request->user()->id
            && $progress->student_enrollment_id === $lessonPlan->student_enrollment_id
            && $progress->lesson_experience_id === $lesson->experience()->value('id'), 404);
        $validated = $request->validate(['response' => ['required', 'array']]);
        $service->saveDraft($progress, $activity, $validated['response']);
        return response()->json(['saved' => true]);
    }
}
