<?php

namespace App\Http\Controllers\Academic;

use App\Domain\Calendars\ScheduledInstructionalDayCalculator;
use App\Http\Controllers\Controller;
use App\Http\Requests\CalendarProfileRequest;
use App\Models\AcademicYearConfiguration;
use App\Models\CalendarProfile;
use App\Models\EducationProvider;
use App\Models\SchoolYear;
use App\Services\AuditService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CalendarProfileController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('calendars.view');

        return Inertia::render('Academic/Calendars/Index', [
            'calendars' => CalendarProfile::query()->with('educationProvider:id,name')->withCount('events')
                ->orderByDesc('start_date')->get()
                ->map(fn ($calendar) => [...$calendar->toArray(), 'is_shared' => $calendar->isShared()]),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('calendars.manage');

        return Inertia::render('Academic/Calendars/Form', [
            'providers' => $this->providers(),
            'defaults' => ['timezone' => app(TenantContext::class)->tenant()->timezone],
        ]);
    }

    public function store(CalendarProfileRequest $request, AuditService $audit): RedirectResponse
    {
        $calendar = CalendarProfile::create($request->validated());
        $audit->record('calendar-profile.created', $calendar, [], $calendar->toArray());

        return redirect()->route('academic.calendars.show', $calendar)->with('success', 'Calendar profile created.');
    }

    public function show(CalendarProfile $calendar, ScheduledInstructionalDayCalculator $calculator): Response
    {
        Gate::authorize('view', $calendar);
        $calendar->load(['events', 'educationProvider:id,name']);

        $summaries = SchoolYear::query()->orderByDesc('start_date')->get()->map(function (SchoolYear $year) use ($calendar, $calculator) {
            $summary = $calculator->summarize(
                $year->start_date->format('Y-m-d'),
                $year->end_date->format('Y-m-d'),
                $year->instructional_weekdays,
                $calendar->events,
            );

            return ['school_year_id' => $year->id, 'school_year_name' => $year->name, ...$summary];
        });

        return Inertia::render('Academic/Calendars/Show', [
            'calendar' => [...$calendar->toArray(), 'is_shared' => $calendar->isShared()],
            'summaries' => $summaries,
        ]);
    }

    public function edit(CalendarProfile $calendar): Response
    {
        Gate::authorize('update', $calendar);

        return Inertia::render('Academic/Calendars/Form', [
            'calendar' => $calendar,
            'providers' => $this->providers(),
        ]);
    }

    public function update(CalendarProfileRequest $request, CalendarProfile $calendar, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $protected = AcademicYearConfiguration::query()
            ->where('calendar_profile_id', $calendar->id)
            ->whereIn('status', ['active', 'closed', 'archived'])
            ->exists();

        if ($protected && collect($data)->except('status')->some(
            fn ($value, $key) => (string) $calendar->getRawOriginal($key) !== (string) ($value ?? ''),
        )) {
            throw ValidationException::withMessages([
                'name' => 'A calendar used by an active or historical configuration may only change status.',
            ]);
        }

        $before = $calendar->toArray();
        $calendar->update($data);
        $audit->record('calendar-profile.updated', $calendar, $before, $calendar->fresh()->toArray());

        return redirect()->route('academic.calendars.show', $calendar)->with('success', 'Calendar profile updated.');
    }

    private function providers()
    {
        return EducationProvider::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'tenant_id']);
    }
}
