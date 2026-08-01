<?php

namespace App\Http\Controllers\Academic;

use App\Domain\Calendars\CalendarProfileCompatibility;
use App\Domain\Calendars\ScheduledInstructionalDayCalculator;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcademicYearConfigurationRequest;
use App\Http\Requests\CopyAcademicConfigurationRequest;
use App\Models\AcademicSource;
use App\Models\AcademicSourceLink;
use App\Models\AcademicYearConfiguration;
use App\Models\CalendarProfile;
use App\Models\CurriculumPackage;
use App\Models\EducationProvider;
use App\Models\SchoolYear;
use App\Models\StandardsFramework;
use App\Services\AuditService;
use App\Services\PermissionService;
use App\Support\SafeExternalUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcademicOverviewController extends Controller
{
    public function index(
        Request $request,
        ScheduledInstructionalDayCalculator $calculator,
        CalendarProfileCompatibility $calendarCompatibility,
    ): Response {
        Gate::authorize('academic-config.view');
        $schoolYears = SchoolYear::query()->orderByDesc('start_date')->get();
        $schoolYear = $schoolYears->firstWhere('id', $request->integer('school_year_id'))
            ?? $schoolYears->firstWhere('status', 'active')
            ?? $schoolYears->first();
        $configuration = $schoolYear?->academicConfiguration()
            ->with(['educationProvider', 'calendarProfile.events', 'standardsFramework', 'curriculumPackage.courseMappings.course.subject'])
            ->first();

        $summary = $schoolYear
            ? $calculator->summarize(
                $schoolYear->start_date->format('Y-m-d'),
                $schoolYear->end_date->format('Y-m-d'),
                $schoolYear->instructional_weekdays,
                $configuration?->calendarProfile?->events ?? collect(),
            )
            : null;
        $mappedCourses = $configuration?->curriculumPackage?->courseMappings->count() ?? 0;
        $calendarSources = $schoolYear ? AcademicSource::query()
            ->with('currentFile')
            ->whereNull('archived_at')
            ->where('source_category', 'calendar')
            ->where(function ($query) use ($schoolYear) {
                $query->where('school_year_id', $schoolYear->id)
                    ->orWhereHas('links', fn ($links) => $links
                        ->where('link_type', 'school_year')
                        ->where('link_id', $schoolYear->id));
            })
            ->latest('updated_at')
            ->get() : collect();
        $eligibleCalendars = $schoolYear ? CalendarProfile::query()
            ->whereIn('status', ['draft', 'active'])
            ->whereDate('start_date', '<=', $schoolYear->start_date->format('Y-m-d'))
            ->whereDate('end_date', '>=', $schoolYear->end_date->format('Y-m-d'))
            ->when($configuration?->education_provider_id, fn ($query, $providerId) => $query
                ->where(fn ($providers) => $providers->whereNull('education_provider_id')->orWhere('education_provider_id', $providerId)))
            ->get() : collect();
        $linkedCalendarIds = $calendarSources->isEmpty() ? collect() : AcademicSourceLink::query()
            ->whereIn('academic_source_id', $calendarSources->pluck('id'))
            ->where('link_type', 'calendar_profile')
            ->pluck('link_id');
        $linkedCalendars = $eligibleCalendars->whereIn('id', $linkedCalendarIds);
        $selectedCalendarSourceIds = $configuration?->calendar_profile_id
            ? AcademicSourceLink::query()
                ->where('link_type', 'calendar_profile')
                ->where('link_id', $configuration->calendar_profile_id)
                ->pluck('academic_source_id')
            : collect();
        $unlinkedCalendarSources = $calendarSources->whereNotIn('id', $selectedCalendarSourceIds);
        $calendarComplete = $schoolYear && $configuration?->calendarProfile
            ? $calendarCompatibility->supports(
                $configuration->calendarProfile,
                $schoolYear,
                $configuration->education_provider_id,
            )
            : false;
        $calendarState = match (true) {
            $calendarComplete => 'complete',
            $linkedCalendars->where('status', 'draft')->isNotEmpty() => 'draft_profile_available',
            $eligibleCalendars->isNotEmpty() => 'profile_available',
            $calendarSources->isNotEmpty() => 'source_available',
            default => 'missing',
        };
        $sourceCounts = $schoolYear ? collect([
            'calendar' => ['calendar'],
            'curriculum' => ['curriculum', 'pacing', 'scope_and_sequence'],
            'courses' => ['course_guide', 'curriculum'],
        ])->map(fn (array $categories) => AcademicSource::query()
            ->whereNull('archived_at')
            ->whereIn('source_category', $categories)
            ->where(function ($query) use ($schoolYear) {
                $query->where('school_year_id', $schoolYear->id)
                    ->orWhereHas('links', fn ($links) => $links
                        ->where('link_type', 'school_year')
                        ->where('link_id', $schoolYear->id));
            })->count())->all() : ['calendar' => 0, 'curriculum' => 0, 'courses' => 0];

        return Inertia::render('Academic/Overview', [
            'schoolYears' => $schoolYears->map(fn ($year) => [
                'id' => $year->id,
                'name' => $year->name,
                'status' => $year->status,
                'start_date' => $year->start_date->format('Y-m-d'),
                'end_date' => $year->end_date->format('Y-m-d'),
                'instructional_day_target' => $year->instructional_day_target,
            ]),
            'schoolYear' => $schoolYear ? [
                'id' => $schoolYear->id,
                'name' => $schoolYear->name,
                'start_date' => $schoolYear->start_date->format('Y-m-d'),
                'end_date' => $schoolYear->end_date->format('Y-m-d'),
                'instructional_day_target' => $schoolYear->instructional_day_target,
            ] : null,
            'configuration' => $configuration,
            'summary' => $summary,
            'mappedCourseCount' => $mappedCourses,
            'sourceCounts' => $sourceCounts,
            'calendarSetup' => [
                'state' => $calendarState,
                'source_count' => $calendarSources->count(),
                'profile_count' => $eligibleCalendars->count(),
                'linked_profile_count' => $linkedCalendars->count(),
                'selected_profile_id' => $configuration?->calendar_profile_id,
                'selected_profile_has_source_website' => SafeExternalUrl::inspect($configuration?->calendarProfile?->source_url) !== null,
                'unlinked_source_count' => $configuration?->calendar_profile_id ? $unlinkedCalendarSources->count() : 0,
                'can_view_sources' => app(PermissionService::class)->allows('academic-sources.view'),
                'can_create_source' => app(PermissionService::class)->allows('academic-sources.create'),
                'single_source' => $calendarSources->count() === 1 ? $calendarSources->first()->only([
                    'id', 'title', 'review_status', 'source_kind',
                ]) : null,
                'can_create_profile' => $calendarSources->count() === 1
                    && $calendarSources->first()->review_status === 'reviewed'
                    && $linkedCalendarIds->isEmpty()
                    && app(PermissionService::class)->allows('calendars.manage'),
            ],
            'checklist' => [
                'school_year' => $schoolYear !== null,
                'provider' => $configuration?->education_provider_id !== null,
                'calendar' => $calendarComplete,
                'standards' => $configuration?->standards_framework_id !== null,
                'curriculum' => $configuration?->curriculum_package_id !== null,
                'courses' => $mappedCourses > 0,
            ],
            'choices' => $this->choices($schoolYear, $configuration?->education_provider_id),
            'canManage' => app(PermissionService::class)->allows('academic-config.manage'),
        ]);
    }

    public function store(AcademicYearConfigurationRequest $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $audit): void {
            $schoolYear = SchoolYear::query()->whereKey($data['school_year_id'])->lockForUpdate()->firstOrFail();
            $configuration = AcademicYearConfiguration::query()
                ->where('school_year_id', $schoolYear->id)->lockForUpdate()->first();
            $before = $configuration?->toArray() ?? [];

            if ($configuration && in_array($configuration->status, ['active', 'closed', 'archived'], true)) {
                $changedRelationships = collect($data)->only([
                    'education_provider_id', 'calendar_profile_id', 'standards_framework_id', 'curriculum_package_id',
                ])->some(fn ($value, $key) => (string) $configuration->{$key} !== (string) ($value ?? ''));

                if ($changedRelationships) {
                    throw ValidationException::withMessages([
                        'status' => 'Active and historical configurations cannot change academic relationships. Copy into a future draft instead.',
                    ]);
                }
            }

            if ($data['status'] === 'active') {
                $missing = collect([
                    'education_provider_id', 'calendar_profile_id', 'standards_framework_id', 'curriculum_package_id',
                ])->first(fn ($field) => empty($data[$field]));
                $packageHasCourses = ! empty($data['curriculum_package_id'])
                    && CurriculumPackage::query()->find($data['curriculum_package_id'])?->courseMappings()->exists();

                if ($missing || ! $packageHasCourses) {
                    throw ValidationException::withMessages([
                        'status' => 'Activation requires provider, calendar, standards, curriculum, and at least one mapped course.',
                    ]);
                }

                $data['configured_by_user_id'] = auth()->id();
                $data['configured_at'] = now();
            }

            $configuration ??= new AcademicYearConfiguration;
            $configuration->fill($data)->save();
            $audit->record(
                $before ? 'academic-configuration.updated' : 'academic-configuration.created',
                $configuration,
                $before,
                $configuration->fresh()->toArray(),
            );
        });

        return back()->with('success', $data['status'] === 'active' ? 'Academic configuration activated.' : 'Academic configuration saved.');
    }

    public function copy(CopyAcademicConfigurationRequest $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $calendarCopied = false;

        DB::transaction(function () use ($data, $audit, &$calendarCopied): void {
            $source = AcademicYearConfiguration::query()
                ->where('school_year_id', $data['source_school_year_id'])->firstOrFail();
            $targetYear = SchoolYear::query()->whereKey($data['target_school_year_id'])->lockForUpdate()->firstOrFail();

            if (AcademicYearConfiguration::query()->where('school_year_id', $targetYear->id)->exists()) {
                throw ValidationException::withMessages([
                    'target_school_year_id' => 'The target school year already has an academic configuration.',
                ]);
            }

            $calendar = $source->calendarProfile;
            $calendarCopied = $calendar
                && $calendar->start_date->format('Y-m-d') <= $targetYear->start_date->format('Y-m-d')
                && $calendar->end_date->format('Y-m-d') >= $targetYear->end_date->format('Y-m-d');

            $copy = AcademicYearConfiguration::create([
                'school_year_id' => $targetYear->id,
                'education_provider_id' => $source->education_provider_id,
                'calendar_profile_id' => $calendarCopied ? $source->calendar_profile_id : null,
                'standards_framework_id' => $source->standards_framework_id,
                'curriculum_package_id' => $source->curriculum_package_id,
                'status' => 'draft',
                'notes' => 'Copied from '.$source->schoolYear->name.'; review required before activation.',
            ]);
            $audit->record('academic-configuration.copied', $copy, [], $copy->toArray());
        });

        $message = 'Prior academic configuration copied as a draft for review.';
        if (! $calendarCopied) {
            $message .= ' The year-specific calendar was not copied because it does not cover the target school year.';
        }

        return redirect()->route('academic.overview', ['school_year_id' => $data['target_school_year_id']])
            ->with('success', $message);
    }

    private function choices(?SchoolYear $schoolYear, ?int $educationProviderId): array
    {
        return [
            'providers' => EducationProvider::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'tenant_id']),
            'calendars' => CalendarProfile::query()
                ->whereIn('status', ['draft', 'active'])
                ->when($schoolYear, fn ($query) => $query
                    ->whereDate('start_date', '<=', $schoolYear->start_date->format('Y-m-d'))
                    ->whereDate('end_date', '>=', $schoolYear->end_date->format('Y-m-d')))
                ->when($educationProviderId, fn ($query) => $query
                    ->where(fn ($providers) => $providers->whereNull('education_provider_id')->orWhere('education_provider_id', $educationProviderId)))
                ->orderByDesc('start_date')->get(['id', 'name', 'start_date', 'end_date', 'status', 'tenant_id']),
            'frameworks' => StandardsFramework::query()->whereIn('status', ['draft', 'active'])->orderBy('name')->get(['id', 'name', 'version_label', 'tenant_id']),
            'packages' => CurriculumPackage::query()->whereIn('status', ['draft', 'active'])->orderByDesc('created_at')->get(['id', 'name', 'version_label', 'status', 'tenant_id']),
        ];
    }
}
