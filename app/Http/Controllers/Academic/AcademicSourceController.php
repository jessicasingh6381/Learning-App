<?php

namespace App\Http\Controllers\Academic;

use App\Domain\AcademicSources\AcademicSourceOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcademicSourceFileRequest;
use App\Http\Requests\AcademicSourceLinkRequest;
use App\Http\Requests\AcademicSourceRequest;
use App\Http\Requests\AcademicSourceReviewRequest;
use App\Http\Requests\CourseRequest;
use App\Models\AcademicSource;
use App\Models\AcademicSourceFile;
use App\Models\AcademicSourceLink;
use App\Models\AcademicYearConfiguration;
use App\Models\CalendarProfile;
use App\Models\Course;
use App\Models\CurriculumPackage;
use App\Models\CurriculumFormatProfile;
use App\Contracts\StandardsDocumentParser;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\StandardsFramework;
use App\Models\Subject;
use App\Services\AcademicSourceFileService;
use App\Services\AcademicSourceLinkService;
use App\Services\AuditService;
use App\Services\CalendarImportLifecycleService;
use App\Services\CurriculumParserCapabilityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AcademicSourceController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AcademicSource::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'in:'.implode(',', AcademicSourceOptions::CATEGORIES)],
            'kind' => ['nullable', 'in:'.implode(',', AcademicSourceOptions::KINDS)],
            'review_status' => ['nullable', 'in:'.implode(',', AcademicSourceOptions::REVIEW_STATUSES)],
            'school_year_id' => ['nullable', 'integer'],
            'education_provider_id' => ['nullable', 'integer'],
            'grade_level_id' => ['nullable', 'integer'],
            'archived' => ['nullable', 'in:active,archived,all'],
        ]);

        $sources = AcademicSource::query()
            ->with(['educationProvider:id,name', 'schoolYear:id,name', 'gradeLevel:id,name', 'currentFile'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(function ($searchQuery) use ($search) {
                $searchQuery->whereLike('title', '%'.$search.'%')->orWhereLike('description', '%'.$search.'%');
            }))
            ->when($filters['category'] ?? null, fn ($query, $value) => $query->where('source_category', $value))
            ->when($filters['kind'] ?? null, fn ($query, $value) => $query->where('source_kind', $value))
            ->when($filters['review_status'] ?? null, fn ($query, $value) => $query->where('review_status', $value))
            ->when($filters['school_year_id'] ?? null, fn ($query, $value) => $query->where('school_year_id', $value))
            ->when($filters['education_provider_id'] ?? null, fn ($query, $value) => $query->where('education_provider_id', $value))
            ->when($filters['grade_level_id'] ?? null, fn ($query, $value) => $query->where('grade_level_id', $value))
            ->when(($filters['archived'] ?? 'active') === 'active', fn ($query) => $query->whereNull('archived_at'))
            ->when(($filters['archived'] ?? 'active') === 'archived', fn ($query) => $query->whereNotNull('archived_at'))
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Academic/Sources/Index', [
            'sources' => $sources,
            'filters' => $filters,
            'filterSummary' => $this->filterSummary($filters),
            'options' => $this->formOptions(),
            'canCreate' => Gate::allows('create', AcademicSource::class),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', AcademicSource::class);

        return Inertia::render('Academic/Sources/Form', [
            'options' => $this->formOptions(),
            'defaults' => [
                'source_kind' => $request->query('kind', 'upload'),
                'source_category' => $request->query('category', 'reference'),
                'school_year_id' => $request->integer('school_year_id') ?: null,
                'education_provider_id' => $request->integer('education_provider_id') ?: null,
                'grade_level_id' => $request->integer('grade_level_id') ?: null,
                'subject_id' => $request->integer('subject_id') ?: null,
            ],
            'maxUploadMegabytes' => (int) config('academic_sources.max_upload_kilobytes') / 1024,
        ]);
    }

    public function store(
        AcademicSourceRequest $request,
        AuditService $audit,
        AcademicSourceFileService $files,
        AcademicSourceLinkService $links,
    ): RedirectResponse {
        $validated = $request->validated();
        $upload = $request->file('source_file');
        $subjectId = $validated['subject_id'] ?? null;
        unset($validated['source_file'], $validated['subject_id']);
        $validated['review_status'] = 'unreviewed';
        $validated['processing_status'] = 'not_requested';
        $validated['retrieved_at'] = $validated['source_kind'] === 'url' ? now() : null;

        $source = DB::transaction(function () use ($validated, $upload, $subjectId, $audit, $files, $links) {
            $source = AcademicSource::create($validated);
            $audit->record('academic-source.created', $source, [], $source->toArray());

            foreach ([
                'education_provider' => $source->education_provider_id,
                'school_year' => $source->school_year_id,
                'grade_level' => $source->grade_level_id,
                'subject' => $subjectId,
            ] as $type => $id) {
                if ($id) {
                    $links->add($source, $type, (int) $id, $audit);
                }
            }

            if ($upload) {
                $files->store($source, $upload, $audit);
            }

            return $source;
        });

        return redirect()->route('academic.sources.show', $source)->with('success', 'Academic source added for review.');
    }

    public function show(
        AcademicSource $source,
        AcademicSourceLinkService $links,
        CurriculumParserCapabilityService $capabilities,
    ): Response
    {
        Gate::authorize('view', $source);
        $source->load([
            'educationProvider:id,name', 'schoolYear:id,name,start_date,end_date,timezone',
            'gradeLevel:id,name', 'files', 'currentFile', 'links',
            'calendarImports' => fn ($query) => $query->withCount(['proposals', 'events']),
            'curriculumImports' => fn ($query) => $query
                ->with('packageCourse.curriculumPackage:id,name')
                ->withCount(['proposals', 'periods', 'units']),
            'curriculumParserCapabilities',
        ]);
        $linkedCalendars = CalendarProfile::query()
            ->whereIn('id', $source->links->where('link_type', 'calendar_profile')->pluck('link_id'))
            ->get(['id', 'name', 'status']);
        $subjectId = $source->links->firstWhere('link_type', 'subject')?->link_id;
        $subject = $subjectId ? Subject::query()->whereKey($subjectId)->first() : null;
        $isCurriculum = in_array($source->source_category, ['curriculum', 'pacing', 'scope_and_sequence'], true);
        $currentStandardsImport = $source->curriculumImports->where('import_type', 'standards')
            ->where('status', '!=', 'superseded')->sortByDesc('id')->first();
        $currentCurriculumImport = $source->curriculumImports->where('import_type', 'curriculum_outline')
            ->where('status', '!=', 'superseded')
            ->sortByDesc(fn ($import) => sprintf('%020d-%020d', $import->created_at?->getTimestamp() ?? 0, $import->id))
            ->first();
        $outlineExists = $currentCurriculumImport
            && $currentCurriculumImport->periods_count > 0
            && $currentCurriculumImport->units_count > 0;
        $capability = $capabilities->cached($source);
        $supportedParser = $capability->state === 'supported' && $capability->parserKey && $capability->parserVersion
            ? $capabilities->parser($capability) : null;
        $isStandardsDocument = $supportedParser instanceof StandardsDocumentParser;
        $standardsActionLabel = 'Import '.($source->gradeLevel?->name ?? 'selected grade').' '.($subject?->name ?? 'subject').' standards';
        $formatProfile = $source->currentFile ? CurriculumFormatProfile::query()->where('example_academic_source_file_id', $source->currentFile->id)->first() : null;
        [$curriculumState, $curriculumActionLabel, $curriculumActionUrl] = match (true) {
            $currentStandardsImport?->status === 'approved' => [
                'standards_imported', 'View imported standards', route('academic.standards-imports.show', $currentStandardsImport),
            ],
            $currentStandardsImport?->status === 'review' => [
                'standards_review', 'Review Grade 5 standards', route('academic.standards-imports.show', $currentStandardsImport),
            ],
            in_array($currentStandardsImport?->status, ['pending', 'processing'], true) => [
                'standards_processing', 'View standards import', route('academic.standards-imports.show', $currentStandardsImport),
            ],
            $currentStandardsImport !== null => [
                'standards_failed', 'Review standards import issue', route('academic.standards-imports.show', $currentStandardsImport),
            ],
            $currentCurriculumImport?->status === 'approved' && $outlineExists => [
                'approved', 'View curriculum outline', route('academic.curriculum.show', $currentCurriculumImport->curriculum_package_id),
            ],
            $currentCurriculumImport?->status === 'approved' => [
                'failed', 'Review import issue', route('academic.curriculum-imports.show', $currentCurriculumImport),
            ],
            $currentCurriculumImport?->status === 'review' => [
                'review', 'Review curriculum outline', route('academic.curriculum-imports.show', $currentCurriculumImport),
            ],
            in_array($currentCurriculumImport?->status, ['pending', 'processing'], true) => [
                'processing', 'View import status', route('academic.curriculum-imports.show', $currentCurriculumImport),
            ],
            $currentCurriculumImport !== null => [
                'failed', 'Review import issue', route('academic.curriculum-imports.show', $currentCurriculumImport),
            ],
            $capability->state === 'supported' && $isStandardsDocument => ['standards_ready', $standardsActionLabel, route('academic.sources.standards-imports.store', $source)],
            $capability->state === 'supported' => ['ready', 'Create curriculum outline', route('academic.sources.curriculum-imports.store', $source)],
            $capability->state === 'unknown' => ['unknown', 'Check outline support', route('academic.sources.curriculum-capability.store', $source)],
            $capability->state === 'unsupported' && $formatProfile?->status === 'draft' => ['format_setup_in_progress', 'Continue document setup', route('academic.curriculum-format-profiles.show', $formatProfile)],
            $capability->state === 'unsupported' => ['format_setup_needed', 'Set up this document format', route('academic.sources.curriculum-format-setup.create', $source)],
            $capability->state === 'ambiguous' => ['ambiguous', null, null],
            default => ['capability_failed', null, null],
        };
        $canSetupFormat = Gate::allows('curriculum.manage') && Gate::allows('update', $source);
        if (in_array($curriculumState, ['format_setup_needed', 'format_setup_in_progress'], true) && ! $canSetupFormat) $curriculumActionUrl = null;

        return Inertia::render('Academic/Sources/Show', [
            'source' => $source,
            'links' => $source->links->map(function (AcademicSourceLink $link) use ($links) {
                $target = $links->resolve($link->link_type, $link->link_id);

                return [
                    'id' => $link->id,
                    'type' => $link->link_type,
                    'label' => $target ? $this->modelLabel($target) : 'Unavailable historical record',
                ];
            }),
            'linkChoices' => $this->linkChoices(),
            'courseChoices' => $this->courseChoices(),
            'courseDefaults' => [
                'subject_id' => $source->links->firstWhere('link_type', 'subject')?->link_id,
                'standards_framework_id' => $source->links->firstWhere('link_type', 'standards_framework')?->link_id,
                'education_provider_id' => $source->education_provider_id,
                'minimum_grade_level_id' => $source->grade_level_id,
                'maximum_grade_level_id' => $source->grade_level_id,
                'name' => $source->title,
                'description' => 'Draft created from reviewed academic source: '.$source->title.'.',
            ],
            'permissions' => [
                'manage' => Gate::allows('update', $source),
                'review' => Gate::allows('review', $source),
                'download' => Gate::allows('download', $source),
            ],
            'reviewTransitions' => AcademicSourceOptions::REVIEW_TRANSITIONS[$source->review_status] ?? [],
            'calendarSetup' => [
                'is_calendar' => $source->source_category === 'calendar',
                'linked_profiles' => $linkedCalendars,
                'current_file_is_pdf' => $source->currentFile?->mime_type === 'application/pdf'
                    && $source->currentFile?->extension === 'pdf',
                'imports' => $source->calendarImports->map(fn ($import) => [
                    'id' => $import->id, 'status' => $import->status,
                    'parser_version' => $import->parser_version,
                    'proposals_count' => $import->proposals_count,
                    'linked_events_count' => $import->events_count,
                    'can_delete' => Gate::allows('update', $source)
                        && CalendarImportLifecycleService::directlyDeletable($import->status, $import->events_count),
                    'created_at' => $import->created_at?->toIso8601String(),
                ]),
            ],
            'curriculumSetup' => [
                'is_curriculum' => $isCurriculum,
                'current_file_is_pdf' => $source->currentFile?->mime_type === 'application/pdf'
                    && $source->currentFile?->extension === 'pdf',
                'subject' => $subject ? ['id' => $subject->id, 'name' => $subject->name, 'code' => $subject->code] : null,
                'workflow_state' => $curriculumState,
                'primary_action_label' => $curriculumActionLabel,
                'primary_action_url' => $curriculumActionUrl,
                'primary_action_method' => in_array($curriculumState, ['ready', 'standards_ready', 'unknown'], true) ? 'post' : 'get',
                'is_standards_document' => $isStandardsDocument || $currentStandardsImport !== null,
                'format_profile' => $formatProfile?->only(['id', 'name', 'document_family', 'status', 'profile_version']),
                'can_setup_format' => $canSetupFormat,
                'capability' => $capability->toArray(Gate::allows('update', $source)),
                'back_url' => route('workspace.curriculum-intake', ['school_year_id' => $source->school_year_id]),
                'imports' => $source->curriculumImports->map(fn ($import) => [
                    'id' => $import->id,
                    'status' => $import->status,
                    'parser_version' => $import->parser_version,
                    'proposals_count' => $import->proposals_count,
                    'created_at' => $import->created_at?->toIso8601String(),
                ]),
            ],
        ]);
    }

    public function edit(AcademicSource $source): Response
    {
        Gate::authorize('update', $source);

        return Inertia::render('Academic/Sources/Form', [
            'source' => $source,
            'options' => $this->formOptions(),
            'maxUploadMegabytes' => (int) config('academic_sources.max_upload_kilobytes') / 1024,
        ]);
    }

    public function update(
        AcademicSourceRequest $request,
        AcademicSource $source,
        AuditService $audit,
        AcademicSourceLinkService $links,
    ): RedirectResponse {
        $before = $source->toArray();
        $data = $request->validated();
        unset($data['source_file']);
        if ($source->source_kind === 'url' && $data['source_url'] !== $source->source_url) {
            $data['retrieved_at'] = now();
        }
        DB::transaction(function () use ($source, $data, $before, $audit, $links): void {
            $source->update($data);
            $audit->record('academic-source.updated', $source, $before, $source->fresh()->toArray());

            foreach ([
                'education_provider_id' => 'education_provider',
                'school_year_id' => 'school_year',
                'grade_level_id' => 'grade_level',
            ] as $field => $type) {
                if ((string) ($before[$field] ?? '') === (string) ($source->{$field} ?? '')) {
                    continue;
                }

                if ($before[$field] ?? null) {
                    $oldLink = $source->links()->where('link_type', $type)->where('link_id', $before[$field])->first();
                    if ($oldLink) {
                        $audit->record('academic-source.unlinked', $oldLink, $oldLink->toArray(), []);
                        $oldLink->delete();
                    }
                }

                if ($source->{$field}) {
                    $links->add($source, $type, (int) $source->{$field}, $audit);
                }
            }
        });

        return redirect()->route('academic.sources.show', $source)->with('success', 'Source metadata updated.');
    }

    public function review(AcademicSourceReviewRequest $request, AcademicSource $source, AuditService $audit): RedirectResponse
    {
        $before = $source->toArray();
        $status = $request->validated('review_status');
        $source->update([
            'review_status' => $status,
            'archived_at' => $status === 'archived' ? now() : $source->archived_at,
        ]);
        $audit->record('academic-source.review-status-changed', $source, $before, $source->fresh()->toArray());

        return back()->with('success', 'Source review status updated.');
    }

    public function archive(AcademicSource $source, AuditService $audit): RedirectResponse
    {
        Gate::authorize('update', $source);
        abort_if($source->archived_at !== null, 422, 'The source is already archived.');
        $before = $source->toArray();
        $source->update(['review_status' => 'archived', 'archived_at' => now()]);
        $audit->record('academic-source.archived', $source, $before, $source->fresh()->toArray());

        return redirect()->route('academic.sources.index')->with('success', 'Academic source archived.');
    }

    public function replaceFile(
        AcademicSourceFileRequest $request,
        AcademicSource $source,
        AcademicSourceFileService $files,
        AuditService $audit,
    ): RedirectResponse {
        $files->store($source, $request->file('source_file'), $audit);

        return back()->with('success', 'A new current file version was uploaded.');
    }

    public function download(
        AcademicSource $source,
        AcademicSourceFile $file,
        AuditService $audit,
    ): StreamedResponse {
        Gate::authorize('download', $source);
        abort_unless($file->academic_source_id === $source->id, 404);
        abort_unless(Storage::disk($file->disk)->exists($file->stored_path), 404);
        $audit->record('academic-source.file-downloaded', $file);

        return Storage::disk($file->disk)->download(
            $file->stored_path,
            $file->original_filename,
            ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function viewFile(
        AcademicSource $source,
        AcademicSourceFile $file,
        AuditService $audit,
    ): StreamedResponse {
        Gate::authorize('download', $source);
        abort_unless($file->academic_source_id === $source->id, 404);
        abort_unless($file->mime_type === 'application/pdf' && $file->extension === 'pdf', 415);
        abort_unless(Storage::disk($file->disk)->exists($file->stored_path), 404);
        $audit->record('academic-source.file-viewed', $file);

        return Storage::disk($file->disk)->response(
            $file->stored_path,
            $file->original_filename,
            ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'],
            'inline',
        );
    }

    public function addLink(
        AcademicSourceLinkRequest $request,
        AcademicSource $source,
        AcademicSourceLinkService $links,
        AuditService $audit,
    ): RedirectResponse {
        $data = $request->validated();
        $links->add($source, $data['link_type'], (int) $data['link_id'], $audit);

        return back()->with('success', 'Academic record linked to source.');
    }

    public function removeLink(AcademicSource $source, AcademicSourceLink $link, AuditService $audit): RedirectResponse
    {
        Gate::authorize('update', $source);
        abort_unless($link->academic_source_id === $source->id, 404);
        if ($link->link_type === 'calendar_profile') {
            $calendar = CalendarProfile::query()->find($link->link_id);
            if ($calendar?->status === 'archived') {
                throw ValidationException::withMessages([
                    'link_id' => 'Restore this Calendar Profile before unlinking source documents.',
                ]);
            }
        }
        $before = $link->toArray();
        DB::transaction(function () use ($link, $audit, $before) {
            $audit->record('academic-source.unlinked', $link, $before, []);
            $link->delete();
        });

        return back()->with('success', 'Source link removed.');
    }

    public function createCalendar(AcademicSource $source, AcademicSourceLinkService $links, AuditService $audit): RedirectResponse
    {
        Gate::authorize('calendars.manage');
        $this->assertReviewedCategory($source, ['calendar']);
        if ($source->links()->where('link_type', 'calendar_profile')->exists()) {
            throw ValidationException::withMessages(['review_status' => 'This source is already linked to a Calendar Profile.']);
        }
        $year = $source->schoolYear;
        if (! $year) {
            throw ValidationException::withMessages(['school_year_id' => 'Select a school year before creating a calendar draft.']);
        }

        $calendar = DB::transaction(function () use ($source, $year, $links, $audit) {
            $calendar = CalendarProfile::create([
                'education_provider_id' => $source->education_provider_id,
                'name' => $source->title,
                'academic_year_label' => $source->academic_year_label ?: $year->name,
                'start_date' => $year->start_date->format('Y-m-d'),
                'end_date' => $year->end_date->format('Y-m-d'),
                'timezone' => $year->timezone,
                'status' => 'draft',
                'source_type' => 'manual',
                'source_url' => $source->source_kind === 'url' ? $source->source_url : null,
                'notes' => 'Draft created from reviewed academic source: '.$source->title.'. No events were extracted.',
            ]);
            $audit->record('calendar-profile.created', $calendar, [], $calendar->toArray());
            $links->add($source, 'calendar_profile', $calendar->id, $audit);
            $audit->record('academic-source.structured-draft-created', $source);

            return $calendar;
        });

        return redirect()->route('academic.calendars.show', $calendar)->with(
            'success',
            'Draft Calendar Profile created from the source. Add calendar events, then select this profile in Academic Setup.',
        );
    }

    public function createCurriculum(AcademicSource $source, AcademicSourceLinkService $links, AuditService $audit): RedirectResponse
    {
        Gate::authorize('curriculum.manage');
        $this->assertReviewedCategory($source, ['curriculum', 'pacing', 'scope_and_sequence']);
        $existingId = $source->links()->where('link_type', 'curriculum_package')->value('link_id');
        if ($existingId && $existing = CurriculumPackage::query()->find($existingId)) {
            return redirect()->route('academic.curriculum.show', $existing)->with('success', 'The existing draft curriculum package is ready to open.');
        }
        $frameworkLink = $source->links()->where('link_type', 'standards_framework')->first();

        $package = DB::transaction(function () use ($source, $frameworkLink, $links, $audit) {
            $package = CurriculumPackage::create([
                'education_provider_id' => $source->education_provider_id,
                'standards_framework_id' => $frameworkLink?->link_id,
                'name' => $source->title,
                'version_label' => $source->version_label ?: ($source->academic_year_label ?: ($source->schoolYear?->name ?: 'Draft 1')),
                'description' => null,
                'status' => 'draft',
                'source_url' => $source->source_kind === 'url' ? $source->source_url : null,
            ]);
            $audit->record('curriculum-package.created', $package, [], $package->toArray());
            $links->add($source, 'curriculum_package', $package->id, $audit);
            $audit->record('academic-source.structured-draft-created', $source);

            return $package;
        });

        return redirect()->route('academic.curriculum.show', $package)->with('success', 'Draft curriculum package created. Add courses manually after review.');
    }

    public function createCourse(
        CourseRequest $request,
        AcademicSource $source,
        AcademicSourceLinkService $links,
        AuditService $audit,
    ): RedirectResponse {
        $this->assertReviewedCategory($source, ['course_guide', 'curriculum']);
        $course = DB::transaction(function () use ($request, $source, $links, $audit) {
            $data = $request->validated();
            $data['status'] = 'draft';
            $course = Course::create($data);
            $audit->record('course.created', $course, [], $course->toArray());
            $links->add($source, 'course', $course->id, $audit);
            $audit->record('academic-source.structured-draft-created', $source);

            return $course;
        });

        return redirect()->route('academic.courses.edit', $course)->with('success', 'Draft course created for adult review.');
    }

    private function assertReviewedCategory(AcademicSource $source, array $categories): void
    {
        Gate::authorize('update', $source);
        if ($source->review_status !== 'reviewed' || ! in_array($source->source_category, $categories, true)) {
            throw ValidationException::withMessages(['review_status' => 'Review this relevant source before creating a structured draft.']);
        }
    }

    private function formOptions(): array
    {
        return [
            'kinds' => AcademicSourceOptions::KINDS,
            'categories' => AcademicSourceOptions::CATEGORIES,
            'authorityLevels' => AcademicSourceOptions::AUTHORITY_LEVELS,
            'reviewStatuses' => AcademicSourceOptions::REVIEW_STATUSES,
            'providers' => EducationProvider::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'tenant_id']),
            'schoolYears' => SchoolYear::query()->orderByDesc('start_date')->get(['id', 'name']),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'subjects' => Subject::query()->where('status', 'active')->orderBy('sort_order')->get(['id', 'name', 'tenant_id']),
        ];
    }

    private function linkChoices(): array
    {
        return [
            'education_provider' => EducationProvider::query()->orderBy('name')->get()->map(fn ($item) => ['id' => $item->id, 'label' => $item->name]),
            'school_year' => SchoolYear::query()->orderByDesc('start_date')->get()->map(fn ($item) => ['id' => $item->id, 'label' => $item->name]),
            'calendar_profile' => CalendarProfile::query()->orderBy('name')->get()->map(fn ($item) => ['id' => $item->id, 'label' => $item->name]),
            'standards_framework' => StandardsFramework::query()->orderBy('name')->get()->map(fn ($item) => ['id' => $item->id, 'label' => $item->name]),
            'grade_level' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn ($item) => ['id' => $item->id, 'label' => $item->name]),
            'subject' => Subject::query()->orderBy('sort_order')->get()->map(fn ($item) => ['id' => $item->id, 'label' => $item->name]),
            'course' => Course::query()->orderBy('name')->get()->map(fn ($item) => ['id' => $item->id, 'label' => $item->name]),
            'curriculum_package' => CurriculumPackage::query()->orderBy('name')->get()->map(fn ($item) => ['id' => $item->id, 'label' => $item->name.' · '.$item->version_label]),
            'academic_configuration' => AcademicYearConfiguration::query()->with('schoolYear:id,name')->get()->map(fn ($item) => ['id' => $item->id, 'label' => $item->schoolYear->name.' · '.$item->status]),
        ];
    }

    private function courseChoices(): array
    {
        return [
            'subjects' => Subject::query()->where('status', 'active')->orderBy('sort_order')->get(['id', 'name', 'tenant_id']),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'providers' => EducationProvider::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'tenant_id']),
            'frameworks' => StandardsFramework::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'tenant_id']),
        ];
    }

    private function filterSummary(array $filters): ?string
    {
        if (($filters['category'] ?? null) !== 'calendar') {
            return null;
        }

        $year = isset($filters['school_year_id'])
            ? SchoolYear::query()->find((int) $filters['school_year_id'])
            : null;
        $provider = isset($filters['education_provider_id'])
            ? EducationProvider::query()->find((int) $filters['education_provider_id'])
            : null;

        return 'Calendar sources'
            .($year ? ' for '.$year->name : '')
            .($provider ? ' · '.$provider->name : '');
    }

    private function modelLabel(Model $model): string
    {
        if ($model instanceof AcademicYearConfiguration) {
            return $model->schoolYear->name.' · '.$model->status;
        }

        return (string) ($model->getAttribute('name') ?? $model->getAttribute('title') ?? $model->getKey());
    }
}
