<?php

namespace App\Http\Controllers;

use App\Http\Requests\SchoolYearRequest;
use App\Models\SchoolYear;
use App\Services\AuditService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SchoolYearController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', SchoolYear::class);

        return Inertia::render('SchoolYears/Index', ['schoolYears' => SchoolYear::query()->orderByDesc('start_date')->get()]);
    }

    public function create(): Response
    {
        Gate::authorize('create', SchoolYear::class);

        return Inertia::render('SchoolYears/Form', ['defaults' => ['timezone' => app(TenantContext::class)->tenant()->timezone]]);
    }

    public function store(SchoolYearRequest $request, AuditService $audit): RedirectResponse
    {
        $year = $this->persist($request->validated());
        $audit->record('school-year.created', $year, [], $year->toArray());

        return redirect()->route('school-years.index')->with('success', 'School year created.');
    }

    public function edit(SchoolYear $schoolYear): Response
    {
        Gate::authorize('update', $schoolYear);

        return Inertia::render('SchoolYears/Form', ['schoolYear' => $schoolYear]);
    }

    public function update(SchoolYearRequest $request, SchoolYear $schoolYear, AuditService $audit): RedirectResponse
    {
        $before = $schoolYear->toArray();
        $this->persist($request->validated(), $schoolYear);
        $audit->record('school-year.updated', $schoolYear, $before, $schoolYear->fresh()->toArray());

        return redirect()->route('school-years.index')->with('success', 'School year updated.');
    }

    private function persist(array $data, ?SchoolYear $year = null): SchoolYear
    {
        return DB::transaction(function () use ($data, $year) {
            if ($data['status'] === 'active') {
                SchoolYear::query()->where('status', 'active')->when($year, fn ($q) => $q->whereKeyNot($year->id))->lockForUpdate()->update(['status' => 'closed']);
            }
            $year ??= new SchoolYear;
            $year->fill($data)->save();

            return $year;
        });
    }
}
