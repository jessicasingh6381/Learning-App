<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\StandardsFrameworkRequest;
use App\Models\AcademicYearConfiguration;
use App\Models\EducationProvider;
use App\Models\StandardsFramework;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StandardsFrameworkController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('standards.view');

        return Inertia::render('Academic/Standards/Index', [
            'frameworks' => StandardsFramework::query()->with('educationProvider:id,name')->orderBy('name')->get()
                ->map(fn ($framework) => [...$framework->toArray(), 'is_shared' => $framework->isShared()]),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('standards.manage');

        return Inertia::render('Academic/Standards/Form', ['providers' => $this->providers()]);
    }

    public function store(StandardsFrameworkRequest $request, AuditService $audit): RedirectResponse
    {
        $framework = StandardsFramework::create($request->validated());
        $audit->record('standards-framework.created', $framework, [], $framework->toArray());

        return redirect()->route('academic.standards.index')->with('success', 'Standards framework created.');
    }

    public function edit(StandardsFramework $framework): Response
    {
        Gate::authorize('update', $framework);

        return Inertia::render('Academic/Standards/Form', [
            'framework' => $framework,
            'providers' => $this->providers(),
        ]);
    }

    public function update(StandardsFrameworkRequest $request, StandardsFramework $framework, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $isHistorical = AcademicYearConfiguration::query()
            ->where('standards_framework_id', $framework->id)
            ->whereIn('status', ['active', 'closed', 'archived'])
            ->exists();

        if ($isHistorical && collect($data)->except('status')->some(
            fn ($value, $key) => (string) $framework->getRawOriginal($key) !== (string) ($value ?? ''),
        )) {
            throw ValidationException::withMessages([
                'name' => 'A framework used by an active or historical configuration may only change status.',
            ]);
        }

        $before = $framework->toArray();
        $framework->update($data);
        $audit->record('standards-framework.updated', $framework, $before, $framework->fresh()->toArray());

        return redirect()->route('academic.standards.index')->with('success', 'Standards framework updated.');
    }

    private function providers()
    {
        return EducationProvider::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'tenant_id']);
    }
}
