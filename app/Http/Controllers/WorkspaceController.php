<?php

namespace App\Http\Controllers;

use App\Services\CurriculumIntakeService;
use App\Services\WorkspaceSummaryService;
use App\Models\LessonPlan;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function home(WorkspaceSummaryService $summary): Response
    {
        Gate::authorize('workspace.view');

        return Inertia::render('Workspace/Home', $summary->build());
    }

    public function learningPlan(
        Request $request,
        WorkspaceSummaryService $summary,
        CurriculumIntakeService $curriculumIntake,
    ): Response {
        Gate::authorize('workspace.view');
        $data = $summary->build();
        $requestedStudentId = $request->integer('student_id');
        $data['selectedStudent'] = collect($data['students'])->firstWhere('id', $requestedStudentId)
            ?? collect($data['students'])->first();
        $intake = $curriculumIntake->build($data['selectedStudent']['id'] ?? null, $data['schoolYear']['id'] ?? null);
        $selectedContextMatches = ($intake['selectedContext']['student_id'] ?? null) === ($data['selectedStudent']['id'] ?? null)
            && ($intake['selectedContext']['school_year_id'] ?? null) === ($data['schoolYear']['id'] ?? null);
        $visibleSubjects = collect($selectedContextMatches ? $intake['subjects'] : []);
        $hiddenSubjects = collect($selectedContextMatches ? $intake['hiddenSubjects'] : []);
        $enrollmentId = $data['selectedStudent']['enrollment']['id'] ?? null;
        $lessonPlans = $enrollmentId ? LessonPlan::query()
            ->where('student_enrollment_id', $enrollmentId)
            ->whereIn('curriculum_import_id', $visibleSubjects->pluck('curriculum_import_id')->filter())
            ->withCount('lessons')->orderByDesc('revision')->get()->unique('curriculum_import_id')->keyBy('curriculum_import_id')
            : collect();
        $visibleSubjects = $visibleSubjects->map(function (array $subject) use ($lessonPlans, $enrollmentId): array {
            $plan = $lessonPlans->get($subject['curriculum_import_id'] ?? null);
            $curriculumReady = $subject['workflow_state'] === 'outline_approved';

            return [...$subject, 'lesson_plan' => $plan ? [
                'id' => $plan->id, 'status' => $plan->status, 'revision' => $plan->revision,
                'lesson_count' => $plan->lessons_count, 'url' => route('lesson-plans.show', $plan),
            ] : null, 'lesson_plan_create_url' => $curriculumReady && $enrollmentId
                ? route('lesson-plans.store', [$enrollmentId, $subject['curriculum_import_id']]) : null];
        });
        $readyCount = $visibleSubjects->where('workflow_state', 'outline_approved')->count();
        $totalCount = $visibleSubjects->count();
        $data['learningPlan']['curriculum_ready_count'] = $readyCount;
        $data['learningPlan']['curriculum_total_count'] = $totalCount;
        $data['learningPlan']['curriculum_status_label'] = $totalCount === 0
            ? 'No active subjects'
            : "{$readyCount} of {$totalCount} subjects ready";
        $data['learningPlan']['curriculum_status_detail'] = match (true) {
            $totalCount === 0 => null,
            $readyCount === $totalCount => 'All active subjects approved',
            $totalCount - $readyCount === 1 => '1 subject still needs curriculum',
            default => ($totalCount - $readyCount).' subjects still need curriculum',
        };
        $data['curriculumBySubject'] = $visibleSubjects->all();
        $data['hiddenCurriculumSubjects'] = $hiddenSubjects->all();
        $data['hiddenCurriculumSubjectCount'] = $hiddenSubjects->count();
        $data['curriculumIntakeAvailable'] = $intake['permissions']['create'];
        $data['curriculumVisibilityManageable'] = $intake['permissions']['manage_visibility'];
        $data['lessonPlanManageable'] = app(PermissionService::class)->allows('lesson-plans.manage');

        return Inertia::render('Workspace/LearningPlan', $data);
    }

    public function calendar(WorkspaceSummaryService $summary): Response
    {
        Gate::authorize('workspace.view');

        return Inertia::render('Workspace/Calendar', $summary->build());
    }

    public function placeholder(string $section): Response
    {
        Gate::authorize('workspace.view');
        abort_unless(in_array($section, ['assignments', 'gradebook', 'attendance', 'reports'], true), 404);

        return Inertia::render('Workspace/Placeholder', ['section' => ucfirst($section)]);
    }

    public function settings(): Response
    {
        Gate::authorize('workspace.view');

        return Inertia::render('Workspace/Settings');
    }
}
