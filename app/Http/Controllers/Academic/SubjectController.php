<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubjectRequest;
use App\Models\Subject;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SubjectController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('subjects.view');

        return Inertia::render('Academic/Subjects/Index', [
            'subjects' => Subject::query()->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn ($subject) => [...$subject->toArray(), 'is_shared' => $subject->isShared()]),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('subjects.manage');

        return Inertia::render('Academic/Subjects/Form');
    }

    public function store(SubjectRequest $request, AuditService $audit): RedirectResponse
    {
        $subject = Subject::create($request->validated());
        $audit->record('subject.created', $subject, [], $subject->toArray());

        return redirect()->route('academic.subjects.index')->with('success', 'Subject created.');
    }

    public function edit(Subject $subject): Response
    {
        Gate::authorize('update', $subject);

        return Inertia::render('Academic/Subjects/Form', ['subject' => $subject]);
    }

    public function update(SubjectRequest $request, Subject $subject, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $isHistorical = DB::table('courses')
            ->join('curriculum_package_courses', 'curriculum_package_courses.course_id', '=', 'courses.id')
            ->join('curriculum_packages', 'curriculum_packages.id', '=', 'curriculum_package_courses.curriculum_package_id')
            ->where('courses.subject_id', $subject->id)
            ->where(function ($query) {
                $query->where('curriculum_packages.status', '!=', 'draft')
                    ->orWhereExists(function ($configurationQuery) {
                        $configurationQuery->selectRaw('1')
                            ->from('academic_year_configurations')
                            ->whereColumn('academic_year_configurations.curriculum_package_id', 'curriculum_packages.id')
                            ->whereIn('academic_year_configurations.status', ['active', 'closed', 'archived']);
                    });
            })
            ->exists();

        if ($isHistorical && collect($data)->except('status')->some(
            fn ($value, $key) => (string) $subject->getRawOriginal($key) !== (string) ($value ?? ''),
        )) {
            throw ValidationException::withMessages([
                'name' => 'A subject used by an active or historical curriculum may only change status.',
            ]);
        }

        $before = $subject->toArray();
        $subject->update($data);
        $audit->record('subject.updated', $subject, $before, $subject->fresh()->toArray());

        return redirect()->route('academic.subjects.index')->with('success', 'Subject updated.');
    }
}
