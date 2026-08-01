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
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\StandardsFramework;
use App\Models\Subject;
use App\Services\AcademicSourceFileService;
use App\Services\AcademicSourceLinkService;
use App\Services\AuditService;
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
                $searchQuery->where('title', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%');
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

    public function show(AcademicSource $source, AcademicSourceLinkService $links): Response
    {
        Gate::authorize('view', $source);
        $source->load(['educationProvider:id,name', 'schoolYear:id,name,start_date,end_date,timezone', 'gradeLevel:id,name', 'files', 'links']);

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

        return redirect()->route('academic.calendars.show', $calendar)->with('success', 'Draft calendar created. Add events manually after reviewing the source.');
    }

    public function createCurriculum(AcademicSource $source, AcademicSourceLinkService $links, AuditService $audit): RedirectResponse
    {
        Gate::authorize('curriculum.manage');
        $this->assertReviewedCategory($source, ['curriculum', 'pacing', 'scope_and_sequence']);
        $frameworkLink = $source->links()->where('link_type', 'standards_framework')->first();

        $package = DB::transaction(function () use ($source, $frameworkLink, $links, $audit) {
            $package = CurriculumPackage::create([
                'education_provider_id' => $source->education_provider_id,
                'standards_framework_id' => $frameworkLink?->link_id,
                'name' => $source->title,
                'version_label' => $source->version_label ?: ($source->academic_year_label ?: ($source->schoolYear?->name ?: 'Draft 1')),
                'description' => 'Draft created from reviewed academic source: '.$source->title.'. No courses, units, or lessons were extracted.',
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

    private function modelLabel(Model $model): string
    {
        if ($model instanceof AcademicYearConfiguration) {
            return $model->schoolYear->name.' · '.$model->status;
        }

        return (string) ($model->getAttribute('name') ?? $model->getAttribute('title') ?? $model->getKey());
    }
}
