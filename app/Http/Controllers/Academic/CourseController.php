<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourseRequest;
use App\Models\Course;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\StandardsFramework;
use App\Models\Subject;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CourseController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('courses.view');
        $subjectId = $request->integer('subject_id') ?: null;
        $gradeId = $request->integer('grade_level_id') ?: null;
        $grade = $gradeId ? GradeLevel::query()->find($gradeId) : null;

        $courses = Course::query()
            ->with(['subject:id,name', 'minimumGradeLevel:id,name,sort_order', 'maximumGradeLevel:id,name,sort_order'])
            ->when($subjectId, fn ($query) => $query->where('subject_id', $subjectId))
            ->when($grade, fn ($query) => $query
                ->whereHas('minimumGradeLevel', fn ($gradeQuery) => $gradeQuery->where('sort_order', '<=', $grade->sort_order))
                ->whereHas('maximumGradeLevel', fn ($gradeQuery) => $gradeQuery->where('sort_order', '>=', $grade->sort_order)))
            ->orderBy('name')->get()
            ->map(fn ($course) => [...$course->toArray(), 'is_shared' => $course->isShared()]);

        return Inertia::render('Academic/Courses/Index', [
            'courses' => $courses,
            'subjects' => $this->subjects(),
            'gradeLevels' => $this->gradeLevels(),
            'filters' => ['subject_id' => $subjectId, 'grade_level_id' => $gradeId],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('courses.manage');

        return Inertia::render('Academic/Courses/Form', $this->choices());
    }

    public function store(CourseRequest $request, AuditService $audit): RedirectResponse
    {
        $course = Course::create($request->validated());
        $audit->record('course.created', $course, [], $course->toArray());

        return redirect()->route('academic.courses.index')->with('success', 'Course created.');
    }

    public function edit(Course $course): Response
    {
        Gate::authorize('update', $course);

        return Inertia::render('Academic/Courses/Form', ['course' => $course, ...$this->choices()]);
    }

    public function update(CourseRequest $request, Course $course, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $isProtected = DB::table('curriculum_package_courses')
            ->join('curriculum_packages', 'curriculum_packages.id', '=', 'curriculum_package_courses.curriculum_package_id')
            ->where('curriculum_package_courses.course_id', $course->id)
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

        if ($isProtected && collect($data)->some(
            fn ($value, $key) => (string) $course->getRawOriginal($key) !== (string) ($value ?? ''),
        )) {
            throw ValidationException::withMessages([
                'name' => 'A course in an active or historical package cannot be changed. Create a new course for a future package version.',
            ]);
        }

        $before = $course->toArray();
        $course->update($data);
        $audit->record('course.updated', $course, $before, $course->fresh()->toArray());

        return redirect()->route('academic.courses.index')->with('success', 'Course updated.');
    }

    private function choices(): array
    {
        return [
            'subjects' => $this->subjects(),
            'gradeLevels' => $this->gradeLevels(),
            'providers' => EducationProvider::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'tenant_id']),
            'frameworks' => StandardsFramework::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'version_label', 'tenant_id']),
        ];
    }

    private function subjects()
    {
        return Subject::query()->where('status', 'active')->orderBy('sort_order')->get(['id', 'name', 'tenant_id']);
    }

    private function gradeLevels()
    {
        return GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'sort_order']);
    }
}
