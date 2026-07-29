<?php

namespace App\Http\Controllers;

use App\Http\Requests\SchoolYearRequest;
use App\Models\SchoolYear;
use App\Models\Tenant;
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
        $this->persist($request->validated(), $audit);

        return redirect()->route('school-years.index')->with('success', 'School year created.');
    }

    public function edit(SchoolYear $schoolYear): Response
    {
        Gate::authorize('update', $schoolYear);

        return Inertia::render('SchoolYears/Form', ['schoolYear' => $schoolYear]);
    }

    public function update(SchoolYearRequest $request, SchoolYear $schoolYear, AuditService $audit): RedirectResponse
    {
        $this->persist($request->validated(), $audit, $schoolYear);

        return redirect()->route('school-years.index')->with('success', 'School year updated.');
    }

    private function persist(array $data, AuditService $audit, ?SchoolYear $year = null): SchoolYear
    {
        return DB::transaction(function () use ($data, $year, $audit) {
            Tenant::query()->whereKey(app(TenantContext::class)->tenantId())->lockForUpdate()->firstOrFail();

            if ($data['status'] === 'active') {
                $previousActiveYears = SchoolYear::query()->where('status', 'active')
                    ->when($year, fn ($query) => $query->whereKeyNot($year->id))
                    ->lockForUpdate()->get();

                foreach ($previousActiveYears as $previousActiveYear) {
                    $before = $previousActiveYear->toArray();
                    $previousActiveYear->update(['status' => 'closed']);
                    $audit->record(
                        'school-year.closed-automatically',
                        $previousActiveYear,
                        $before,
                        $previousActiveYear->fresh()->toArray(),
                    );
                }
            }

            $before = $year?->toArray() ?? [];
            $year ??= new SchoolYear;
            $year->fill($data)->save();
            $audit->record(
                $before ? 'school-year.updated' : 'school-year.created',
                $year,
                $before,
                $year->fresh()->toArray(),
            );

            return $year;
        });
    }
}
