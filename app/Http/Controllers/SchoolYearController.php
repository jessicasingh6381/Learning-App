<?php

namespace App\Http\Controllers;

use App\Domain\SchoolYears\BaseInstructionalDayCalculator;
use App\Domain\SchoolYears\InstructionalSchedule;
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
    public function index(BaseInstructionalDayCalculator $calculator): Response
    {
        Gate::authorize('viewAny', SchoolYear::class);

        $schoolYears = SchoolYear::query()
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (SchoolYear $schoolYear): array => $this->serializeSchoolYear($schoolYear, $calculator));

        return Inertia::render('SchoolYears/Index', ['schoolYears' => $schoolYears]);
    }

    public function create(): Response
    {
        Gate::authorize('create', SchoolYear::class);

        return Inertia::render('SchoolYears/Form', [
            'defaults' => [
                'timezone' => app(TenantContext::class)->tenant()->timezone,
                'instructional_week_type' => 'five_day',
                'instructional_weekdays' => InstructionalSchedule::PRESET_WEEKDAYS['five_day'],
            ],
        ]);
    }

    public function store(SchoolYearRequest $request, AuditService $audit): RedirectResponse
    {
        $this->persist($request->validated(), $audit);

        return redirect()->route('school-years.index')->with('success', 'School year created.');
    }

    public function edit(
        SchoolYear $schoolYear,
        BaseInstructionalDayCalculator $calculator,
    ): Response {
        Gate::authorize('update', $schoolYear);

        return Inertia::render('SchoolYears/Form', [
            'schoolYear' => $this->serializeSchoolYear($schoolYear, $calculator),
        ]);
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

    /**
     * @return array<string, mixed>
     */
    private function serializeSchoolYear(
        SchoolYear $schoolYear,
        BaseInstructionalDayCalculator $calculator,
    ): array {
        $startDate = $schoolYear->start_date->format('Y-m-d');
        $endDate = $schoolYear->end_date->format('Y-m-d');
        $weekdays = $schoolYear->instructional_weekdays;

        return [
            'id' => $schoolYear->id,
            'name' => $schoolYear->name,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'timezone' => $schoolYear->timezone,
            'status' => $schoolYear->status,
            'instructional_week_type' => $schoolYear->instructional_week_type,
            'instructional_weekdays' => $weekdays,
            'instructional_weekday_label' => InstructionalSchedule::label($weekdays),
            'base_instructional_days' => $calculator->calculate($startDate, $endDate, $weekdays),
            'instructional_day_target' => $schoolYear->instructional_day_target,
        ];
    }
}
