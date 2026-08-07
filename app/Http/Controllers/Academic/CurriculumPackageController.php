<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\CurriculumPackageCourseRequest;
use App\Http\Requests\CurriculumPackageRequest;
use App\Models\AcademicYearConfiguration;
use App\Models\Course;
use App\Models\CurriculumPackage;
use App\Models\CurriculumPackageCourse;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\StandardsFramework;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CurriculumPackageController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('curriculum.view');

        return Inertia::render('Academic/Curriculum/Index', [
            'packages' => CurriculumPackage::query()->withCount('courseMappings')->orderByDesc('created_at')->get()
                ->map(fn ($package) => [...$package->toArray(), 'is_shared' => $package->isShared()]),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('curriculum.manage');

        return Inertia::render('Academic/Curriculum/Form', $this->choices());
    }

    public function store(CurriculumPackageRequest $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $data['status'] = 'draft';
        $package = CurriculumPackage::create($data);
        $audit->record('curriculum-package.created', $package, [], $package->toArray());

        return redirect()->route('academic.curriculum.show', $package)->with('success', 'Draft curriculum package created.');
    }

    public function show(CurriculumPackage $package): Response
    {
        Gate::authorize('view', $package);
        $package->load([
            'courseMappings.course.subject', 'courseMappings.gradeLevel',
            'courseMappings.curriculumPeriods.units.standardAlignments',
            'courseMappings.curriculumPeriods.units.components.descendants',
        ]);

        return Inertia::render('Academic/Curriculum/Show', [
            'package' => [...$package->toArray(), 'is_shared' => $package->isShared()],
            'courses' => Course::query()->whereIn('status', ['draft', 'active'])->with('subject:id,name')->orderBy('name')->get(),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
        ]);
    }

    public function edit(CurriculumPackage $package): Response
    {
        Gate::authorize('update', $package);

        return Inertia::render('Academic/Curriculum/Form', ['package' => $package, ...$this->choices()]);
    }

    public function update(CurriculumPackageRequest $request, CurriculumPackage $package, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $isReferenced = AcademicYearConfiguration::query()->where('curriculum_package_id', $package->id)->exists();

        if ($package->status !== 'draft' && collect($data)->except('status')->some(
            fn ($value, $key) => (string) $package->getRawOriginal($key) !== (string) ($value ?? ''),
        )) {
            throw ValidationException::withMessages([
                'name' => 'Only draft packages may be materially edited. Create a new version for future changes.',
            ]);
        }

        if ($isReferenced && $data['status'] === 'draft') {
            throw ValidationException::withMessages(['status' => 'A referenced package cannot return to draft status.']);
        }

        if ($data['status'] === 'active' && ! $package->courseMappings()->exists()) {
            throw ValidationException::withMessages([
                'status' => 'Add at least one course before activating this package.',
            ]);
        }

        $before = $package->toArray();
        $package->update($data);
        $audit->record('curriculum-package.updated', $package, $before, $package->fresh()->toArray());

        return redirect()->route('academic.curriculum.show', $package)->with('success', 'Curriculum package updated.');
    }

    public function addCourse(
        CurriculumPackageCourseRequest $request,
        CurriculumPackage $package,
        AuditService $audit,
    ): RedirectResponse {
        $mapping = $package->courseMappings()->create($request->validated());
        $audit->record('curriculum-package.course-added', $mapping, [], $mapping->toArray());

        return back()->with('success', 'Course added to package.');
    }

    public function updateCourse(
        CurriculumPackageCourseRequest $request,
        CurriculumPackage $package,
        CurriculumPackageCourse $mapping,
        AuditService $audit,
    ): RedirectResponse {
        abort_unless($mapping->curriculum_package_id === $package->id, 404);
        $before = $mapping->toArray();
        $mapping->update($request->validated());
        $audit->record('curriculum-package.course-updated', $mapping, $before, $mapping->fresh()->toArray());

        return back()->with('success', 'Course mapping updated.');
    }

    public function removeCourse(
        CurriculumPackage $package,
        CurriculumPackageCourse $mapping,
        AuditService $audit,
    ): RedirectResponse {
        Gate::authorize('update', $package);
        abort_unless($mapping->curriculum_package_id === $package->id, 404);
        abort_unless($package->status === 'draft', 422, 'Only draft package mappings may be removed.');

        DB::transaction(function () use ($mapping, $audit): void {
            $before = $mapping->toArray();
            $audit->record('curriculum-package.course-removed', $mapping, $before, []);
            $mapping->delete();
        });

        return back()->with('success', 'Course removed from package.');
    }

    private function choices(): array
    {
        return [
            'providers' => EducationProvider::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'tenant_id']),
            'frameworks' => StandardsFramework::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'version_label', 'tenant_id']),
        ];
    }
}
