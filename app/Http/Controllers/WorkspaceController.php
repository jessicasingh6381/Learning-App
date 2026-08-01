<?php

namespace App\Http\Controllers;

use App\Services\CurriculumIntakeService;
use App\Services\WorkspaceSummaryService;
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
        $data['curriculumBySubject'] = $intake['subjects'];
        $data['curriculumIntakeAvailable'] = $intake['permissions']['create'];

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
