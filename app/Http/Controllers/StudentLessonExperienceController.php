<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonActivity;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentLessonProgress;
use App\Services\LessonExperiencePresenter;
use App\Services\LessonExperienceService;
use App\Services\LessonAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentLessonExperienceController extends Controller
{
    public function show(Request $request, Lesson $lesson, LessonExperienceService $service, LessonExperiencePresenter $presenter): Response
    {
        $enrollment = $this->authorizedEnrollment($request, $lesson);
        $experience = $lesson->experience()->where('status', 'available')->firstOrFail();
        $progress = $service->progress($experience, $enrollment, false);
        return Inertia::render('StudentExperiences/Show', array_merge($presenter->props($progress, false,
            fn ($activity) => route('student.lessons.experience.respond', [$lesson, $progress, $activity]),
            fn ($activity) => route('student.lessons.experience.draft', [$lesson, $progress, $activity]),
            fn ($resource) => route('student.lessons.resources.show', [$lesson, $resource])), [
                'return_url' => route('student.learning'),
            ]));
    }

    public function respond(Request $request, Lesson $lesson, StudentLessonProgress $progress, LessonActivity $activity, LessonExperienceService $service): RedirectResponse
    {
        $enrollment = $this->authorizedEnrollment($request, $lesson);
        abort_unless(! $progress->is_preview && $progress->student_enrollment_id === $enrollment->id
            && $progress->lesson_experience_id === $lesson->experience()->where('status', 'available')->value('id'), 404);
        $validated = $request->validate(['response' => ['required', 'array']]);
        $service->respond($progress, $activity, $validated['response']);
        return back();
    }

    public function saveDraft(Request $request, Lesson $lesson, StudentLessonProgress $progress, LessonActivity $activity, LessonExperienceService $service): JsonResponse
    {
        $enrollment = $this->authorizedEnrollment($request, $lesson);
        abort_unless(! $progress->is_preview && $progress->student_enrollment_id === $enrollment->id
            && $progress->lesson_experience_id === $lesson->experience()->where('status', 'available')->value('id'), 404);
        $validated = $request->validate(['response' => ['required', 'array']]);
        $service->saveDraft($progress, $activity, $validated['response']);
        return response()->json(['saved' => true]);
    }

    private function authorizedEnrollment(Request $request, Lesson $lesson): StudentEnrollment
    {
        $student = Student::query()->where('user_id', $request->user()->id)->firstOrFail();
        $lesson->loadMissing('lessonPlan');
        $enrollment = $student->enrollments()->whereKey($lesson->lessonPlan->student_enrollment_id)
            ->where('status', 'active')->firstOrFail();
        abort_unless(app(LessonAvailabilityService::class)->canAccess($lesson, $enrollment), 404);

        return $enrollment;
    }
}
