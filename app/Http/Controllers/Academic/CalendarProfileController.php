<?php

namespace App\Http\Controllers\Academic;

use App\Domain\Calendars\CalendarProfileLifecycleService;
use App\Domain\Calendars\ScheduledInstructionalDayCalculator;
use App\Http\Controllers\Controller;
use App\Http\Requests\CalendarProfileRequest;
use App\Models\AcademicSource;
use App\Models\AcademicSourceLink;
use App\Models\AcademicYearConfiguration;
use App\Models\CalendarProfile;
use App\Models\EducationProvider;
use App\Models\SchoolYear;
use App\Services\AuditService;
use App\Support\SafeExternalUrl;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CalendarProfileController extends Controller
{
    public function index(Request $request, CalendarProfileLifecycleService $lifecycle): Response
    {
        Gate::authorize('calendars.view');
        $filters = $request->validate(['show' => ['nullable', 'in:active,archived,all']]);
        $show = $filters['show'] ?? 'active';
        $calendars = CalendarProfile::query()->with('educationProvider:id,name')->withCount('events')
            ->when($show === 'active', fn ($query) => $query->whereIn('status', ['draft', 'active']))
            ->when($show === 'archived', fn ($query) => $query->where('status', 'archived'))
            ->orderByDesc('start_date')->get();
        $sourceLinks = $this->sourceLinks($calendars->pluck('id')->all());

        return Inertia::render('Academic/Calendars/Index', [
            'calendars' => $calendars->map(fn ($calendar) => [
                'id' => $calendar->id,
                'name' => $calendar->name,
                'academic_year_label' => $calendar->academic_year_label,
                'start_date' => $calendar->start_date->format('Y-m-d'),
                'end_date' => $calendar->end_date->format('Y-m-d'),
                'status' => $calendar->status,
                'events_count' => $calendar->events_count,
                'education_provider' => $calendar->educationProvider,
                'is_shared' => $calendar->isShared(),
                'has_source_website' => SafeExternalUrl::inspect($calendar->source_url) !== null,
                'linked_sources' => $sourceLinks->get($calendar->id, collect())->values(),
                'lifecycle' => $lifecycle->inspect($calendar),
            ]),
            'filters' => ['show' => $show],
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

    public function show(
        CalendarProfile $calendar,
        ScheduledInstructionalDayCalculator $calculator,
        CalendarProfileLifecycleService $lifecycle,
    ): Response {
        Gate::authorize('view', $calendar);
        $calendar->load(['events', 'educationProvider:id,name']);
        $linkedSources = $this->sourceLinks([$calendar->id])->get($calendar->id, collect())->values();
        $suggestedSources = $this->compatibleSources($calendar)->map(fn (AcademicSource $source) => $this->sourcePayload($source));

        $summaries = SchoolYear::query()->orderByDesc('start_date')->get()->map(function (SchoolYear $year) use ($calendar, $calculator) {
            $summary = $calculator->summarize(
                $year->start_date->format('Y-m-d'),
                $year->end_date->format('Y-m-d'),
                $year->instructional_weekdays,
                $calendar->events,
            );

            return [
                'school_year_id' => $year->id,
                'school_year_name' => $year->name,
                'compatible' => in_array($calendar->status, ['draft', 'active'], true)
                    && $calendar->start_date->format('Y-m-d') <= $year->start_date->format('Y-m-d')
                    && $calendar->end_date->format('Y-m-d') >= $year->end_date->format('Y-m-d'),
                ...$summary,
            ];
        });

        return Inertia::render('Academic/Calendars/Show', [
            'calendar' => [
                'id' => $calendar->id,
                'name' => $calendar->name,
                'academic_year_label' => $calendar->academic_year_label,
                'start_date' => $calendar->start_date->format('Y-m-d'),
                'end_date' => $calendar->end_date->format('Y-m-d'),
                'timezone' => $calendar->timezone,
                'status' => $calendar->status,
                'source_type' => $calendar->source_type,
                'source_version' => $calendar->source_version,
                'education_provider' => $calendar->educationProvider,
                'events' => $calendar->events->map(fn ($event) => $event->only([
                    'id', 'event_date', 'end_date', 'event_type', 'name', 'instructional_effect', 'status', 'notes', 'source_reference',
                ])),
                'is_shared' => $calendar->isShared(),
            ],
            'sourceWebsite' => SafeExternalUrl::inspect($calendar->source_url),
            'summaries' => $summaries,
            'linkedSources' => $linkedSources,
            'suggestedSources' => $suggestedSources,
            'lifecycle' => $lifecycle->inspect($calendar),
        ]);
    }

    public function edit(CalendarProfile $calendar): Response
    {
        Gate::authorize('update', $calendar);
        abort_if($calendar->status === 'archived', 409, 'Restore this Calendar Profile before editing it.');

        return Inertia::render('Academic/Calendars/Form', [
            'calendar' => $calendar,
            'providers' => $this->providers(),
        ]);
    }

    public function update(CalendarProfileRequest $request, CalendarProfile $calendar, AuditService $audit): RedirectResponse
    {
        abort_if($calendar->status === 'archived', 409, 'Restore this Calendar Profile before editing it.');
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

    public function archive(CalendarProfile $calendar, CalendarProfileLifecycleService $lifecycle): RedirectResponse
    {
        Gate::authorize('update', $calendar);
        $lifecycle->archive($calendar);

        return redirect()->route('academic.calendars.index')->with('success', 'Calendar Profile archived. Events, source documents, and history were preserved.');
    }

    public function restore(CalendarProfile $calendar, CalendarProfileLifecycleService $lifecycle): RedirectResponse
    {
        Gate::authorize('update', $calendar);
        $lifecycle->restore($calendar);

        return redirect()->route('academic.calendars.show', $calendar)->with('success', 'Calendar Profile restored to Draft.');
    }

    public function destroy(Request $request, CalendarProfile $calendar, CalendarProfileLifecycleService $lifecycle): RedirectResponse
    {
        Gate::authorize('update', $calendar);
        $request->validate(['confirmation' => ['required', 'in:DELETE']]);
        $name = $lifecycle->deletePermanently($calendar);

        return redirect()->route('academic.calendars.index')->with('success', $name.' was permanently deleted.');
    }

    private function providers()
    {
        return EducationProvider::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'tenant_id']);
    }

    private function sourceLinks(array $calendarIds)
    {
        return AcademicSourceLink::query()
            ->with(['source.educationProvider:id,name', 'source.currentFile'])
            ->where('link_type', 'calendar_profile')
            ->whereIn('link_id', $calendarIds)
            ->get()
            ->map(fn (AcademicSourceLink $link) => [
                ...$this->sourcePayload($link->source),
                'link_id' => $link->id,
                'calendar_profile_id' => $link->link_id,
            ])
            ->groupBy('calendar_profile_id');
    }

    private function compatibleSources(CalendarProfile $calendar)
    {
        return AcademicSource::query()
            ->with(['educationProvider:id,name', 'currentFile'])
            ->whereNull('archived_at')
            ->where('source_category', 'calendar')
            ->whereDoesntHave('links', fn ($links) => $links
                ->where('link_type', 'calendar_profile')
                ->where('link_id', $calendar->id))
            ->when($calendar->education_provider_id, fn ($query, $providerId) => $query
                ->where(fn ($providers) => $providers
                    ->whereNull('education_provider_id')
                    ->orWhere('education_provider_id', $providerId)))
            ->where(function ($query) use ($calendar) {
                $query->whereHas('schoolYear', fn ($years) => $years
                    ->whereDate('start_date', '>=', $calendar->start_date->format('Y-m-d'))
                    ->whereDate('end_date', '<=', $calendar->end_date->format('Y-m-d')));

                if (filled($calendar->academic_year_label)) {
                    $query->orWhere('academic_year_label', $calendar->academic_year_label);
                }
            })
            ->latest('updated_at')
            ->get();
    }

    private function sourcePayload(AcademicSource $source): array
    {
        $file = $source->currentFile;

        return [
            'id' => $source->id,
            'title' => $source->title,
            'source_kind' => $source->source_kind,
            'source_category' => $source->source_category,
            'authority_level' => $source->authority_level,
            'review_status' => $source->review_status,
            'education_provider' => $source->educationProvider,
            'external_url' => SafeExternalUrl::inspect($source->source_url),
            'current_file' => $file ? [
                'id' => $file->id,
                'original_filename' => $file->original_filename,
                'is_pdf' => $file->mime_type === 'application/pdf' && $file->extension === 'pdf',
            ] : null,
            'can_manage' => Gate::allows('update', $source),
            'can_download' => Gate::allows('download', $source),
        ];
    }
}
