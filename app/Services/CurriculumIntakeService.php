<?php

namespace App\Services;

use App\Contracts\StandardsDocumentParser;
use App\Models\AcademicSource;
use App\Models\CurriculumImport;
use App\Models\CurriculumFormatProfile;
use App\Models\CurriculumPackage;
use App\Models\EducationProvider;
use App\Models\LearningPlanSubjectPreference;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Support\SafeExternalUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class CurriculumIntakeService
{
    public function __construct(private CurriculumParserCapabilityService $capabilities) {}

    /** @return array<string, mixed> */
    public function buildAdd(int $studentId, int $schoolYearId, int $subjectId): array
    {
        $enrollment = StudentEnrollment::query()
            ->where('student_id', $studentId)
            ->where('school_year_id', $schoolYearId)
            ->whereIn('status', ['planned', 'active'])
            ->whereHas('student', fn ($query) => $query->where('status', 'active'))
            ->whereHas('schoolYear', fn ($query) => $query->whereIn('status', ['draft', 'active']))
            ->with([
                'student:id,first_name,last_name,preferred_name,status',
                'schoolYear:id,name,start_date,end_date,status',
                'schoolYear.academicConfiguration.educationProvider',
                'gradeLevel:id,name',
            ])
            ->firstOrFail();
        $subject = Subject::query()->whereKey($subjectId)->where('status', 'active')->firstOrFail();
        $configuration = $enrollment->schoolYear->academicConfiguration;
        $provider = $configuration?->educationProvider;
        abort_if($configuration?->education_provider_id && ! $provider, 404);
        if (! $provider) {
            $available = EducationProvider::query()->where('status', 'active')->get();
            $provider = $available->count() === 1 ? $available->first() : null;
        }

        return [
            'contexts' => [],
            'selectedContext' => $this->context($enrollment),
            'selectedSubject' => ['id' => $subject->id, 'name' => $subject->name, 'code' => $subject->code],
            'contextProvider' => $provider ? [
                'id' => $provider->id,
                'name' => $provider->name,
                'short_name' => $provider->short_name,
                'provider_type' => $provider->provider_type,
            ] : null,
            'providers' => [],
            'subjects' => [],
            'hiddenSubjects' => [],
            'hiddenSubjectCount' => 0,
            'permissions' => $this->permissions(),
        ];
    }

    /** @return array<string, mixed> */
    public function build(?int $studentId = null, ?int $schoolYearId = null): array
    {
        $enrollments = StudentEnrollment::query()
            ->whereIn('status', ['planned', 'active'])
            ->whereHas('student', fn ($query) => $query->where('status', 'active'))
            ->whereHas('schoolYear', fn ($query) => $query->whereIn('status', ['draft', 'active']))
            ->with(['student:id,first_name,last_name,preferred_name,status', 'schoolYear:id,name,start_date,end_date,status', 'gradeLevel:id,name'])
            ->get();
        $selected = $enrollments->first(fn ($enrollment) => $enrollment->student_id === $studentId && $enrollment->school_year_id === $schoolYearId)
            ?? $enrollments->first(fn ($enrollment) => $enrollment->student_id === $studentId && $enrollment->schoolYear->status === 'active')
            ?? $enrollments->first(fn ($enrollment) => $enrollment->schoolYear->status === 'active')
            ?? $enrollments->first();
        $subjects = Subject::query()->where('status', 'active')->orderBy('sort_order')->get(['id', 'name', 'code', 'tenant_id']);
        $overview = $selected ? $this->subjects($subjects, $selected->student_id, $selected->school_year_id, $selected->grade_level_id) : $subjects->map(fn ($subject) => $this->emptySubject($subject));
        $hiddenSubjectIds = $selected ? LearningPlanSubjectPreference::query()
            ->where('student_enrollment_id', $selected->id)->where('is_hidden', true)->pluck('subject_id') : collect();
        $hiddenSubjects = $overview->whereIn('id', $hiddenSubjectIds)->values();
        $visibleSubjects = $overview->whereNotIn('id', $hiddenSubjectIds)->values();

        return [
            'contexts' => $enrollments->map(fn ($enrollment) => [
                'student_id' => $enrollment->student_id,
                'student_name' => $enrollment->student->display_name,
                'school_year_id' => $enrollment->school_year_id,
                'school_year_name' => $enrollment->schoolYear->name,
                'school_year_status' => $enrollment->schoolYear->status,
                'grade_level_id' => $enrollment->grade_level_id,
                'grade_name' => $enrollment->gradeLevel->name,
                'enrollment_status' => $enrollment->status,
            ])->values()->all(),
            'selectedContext' => $selected ? $this->context($selected) : null,
            'providers' => EducationProvider::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'short_name', 'provider_type', 'tenant_id']),
            'subjects' => $visibleSubjects->all(),
            'hiddenSubjects' => $hiddenSubjects->all(),
            'hiddenSubjectCount' => $hiddenSubjects->count(),
            'permissions' => $this->permissions(),
        ];
    }

    /** @return array<string, mixed> */
    private function context(StudentEnrollment $enrollment): array
    {
        return [
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'student_name' => $enrollment->student->display_name,
            'school_year_id' => $enrollment->school_year_id,
            'school_year_name' => $enrollment->schoolYear->name,
            'school_year_status' => $enrollment->schoolYear->status,
            'grade_level_id' => $enrollment->grade_level_id,
            'grade_name' => $enrollment->gradeLevel->name,
            'enrollment_status' => $enrollment->status,
        ];
    }

    /** @return array<string, bool> */
    private function permissions(): array
    {
        return [
            'create' => Gate::allows('create', AcademicSource::class),
            'review' => app(PermissionService::class)->allows('academic-sources.review'),
            'manage' => app(PermissionService::class)->allows('academic-sources.manage'),
            'create_draft' => app(PermissionService::class)->allows('curriculum.manage'),
            'advanced' => app(PermissionService::class)->allows('advanced-academic.view'),
            'manage_visibility' => Gate::allows('create', AcademicSource::class),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function subjects(Collection $subjects, int $studentId, int $schoolYearId, int $gradeLevelId): Collection
    {
        $sources = AcademicSource::query()
            ->whereNull('archived_at')
            ->whereIn('source_category', ['curriculum', 'pacing', 'scope_and_sequence'])
            ->where('school_year_id', $schoolYearId)
            ->where('grade_level_id', $gradeLevelId)
            ->whereHas('links', fn ($query) => $query->where('link_type', 'subject')->whereIn('link_id', $subjects->pluck('id')))
            ->with([
                'educationProvider:id,name,short_name', 'currentFile', 'links', 'curriculumParserCapabilities',
                'curriculumImports' => fn ($query) => $query
                    ->where('status', '!=', 'superseded')
                    ->where('school_year_id', $schoolYearId)
                    ->where('grade_level_id', $gradeLevelId)
                    ->with(['packageCourse.curriculumPackage', 'packageCourse.course'])
                    ->withCount([
                        'periods', 'units',
                        'standards',
                        'units as assessment_count' => fn ($units) => $units->where('unit_type', 'assessment'),
                        'standardAlignments',
                    ]),
            ])
            ->latest('updated_at')->latest('id')
            ->get();
        $packages = CurriculumPackage::query()
            ->whereIn('id', $sources->flatMap(fn ($source) => $source->links->where('link_type', 'curriculum_package')->pluck('link_id'))->unique())
            ->with('courseMappings.course.subject')
            ->get()->keyBy('id');

        return $subjects->map(function ($subject) use ($sources, $packages, $studentId, $gradeLevelId, $schoolYearId): array {
            $subjectSources = $sources->filter(fn ($source) => $source->links->contains(fn ($link) => $link->link_type === 'subject' && $link->link_id === $subject->id));
            $sourceItems = $subjectSources->map(fn ($source) => $this->source($source, $packages))->values();
            $workflow = $this->workflow($subject, $subjectSources, $studentId, $schoolYearId, $gradeLevelId);

            return [
                'id' => $subject->id,
                'name' => $subject->name,
                'code' => $subject->code,
                'status' => $workflow['workflow_state'],
                ...$workflow,
                'source_count' => $subjectSources->count(),
                'sources' => $sourceItems->all(),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function source(AcademicSource $source, Collection $packages): array
    {
        $packageId = $source->links->firstWhere('link_type', 'curriculum_package')?->link_id;
        $package = $packageId ? $packages->get($packageId) : null;
        $safeUrl = SafeExternalUrl::inspect($source->source_url);

        return [
            'id' => $source->id,
            'title' => $source->title,
            'source_kind' => $source->source_kind,
            'review_status' => $source->review_status,
            'provider' => $source->educationProvider?->short_name ?: $source->educationProvider?->name,
            'current_file' => $source->currentFile ? [
                'id' => $source->currentFile->id,
                'original_filename' => $source->currentFile->original_filename,
                'is_pdf' => $source->currentFile->mime_type === 'application/pdf' && $source->currentFile->extension === 'pdf',
            ] : null,
            'external_url' => $safeUrl['url'] ?? null,
            'draft' => $package ? ['id' => $package->id, 'name' => $package->name, 'status' => $package->status] : null,
            'can_review' => Gate::allows('review', $source),
            'can_manage' => Gate::allows('update', $source),
            'can_download' => Gate::allows('download', $source),
        ];
    }

    /** @return array<string, mixed> */
    private function emptySubject($subject): array
    {
        return [
            'id' => $subject->id, 'name' => $subject->name, 'code' => $subject->code,
            'status' => 'not_started', 'workflow_state' => 'not_started', 'status_label' => 'Not started',
            'primary_action_label' => null, 'primary_action_url' => null,
            'secondary_action_label' => null, 'secondary_action_url' => null,
            'standards_import_id' => null, 'standards_count' => 0,
            'source_id' => null, 'source_review_status' => null, 'curriculum_import_id' => null,
            'curriculum_import_status' => null, 'package_id' => null, 'package_course_id' => null,
            'period_count' => 0, 'unit_count' => 0, 'assessment_count' => 0,
            'standard_alignment_count' => 0, 'source_count' => 0, 'sources' => [],
        ];
    }

    /**
     * Standards and pacing are resolved independently. Within each track, the
     * newest matching non-superseded import wins; otherwise the most recently
     * updated active source determines the next source step.
     *
     * @return array<string, mixed>
     */
    private function workflow($subject, Collection $sources, int $studentId, int $schoolYearId, int $gradeLevelId): array
    {
        $standardsCandidates = $sources->flatMap(fn (AcademicSource $source) => $source->curriculumImports
            ->filter(fn (CurriculumImport $import) => $import->import_type === 'standards'
                && $this->importContextMatches($import, $subject->id, $schoolYearId, $gradeLevelId))
            ->map(fn (CurriculumImport $import) => ['source' => $source, 'import' => $import]));
        $standardsSelected = $this->newestImport($standardsCandidates);
        /** @var CurriculumImport|null $standardsImport */
        $standardsImport = $standardsSelected['import'] ?? null;
        $standardsSource = $standardsSelected['source'] ?? null;
        $approvedStandards = $standardsImport?->status === 'approved' && (int) $standardsImport->standards_count > 0;

        // Standards describe required learning, not its teaching sequence or pacing.
        $pacingSources = $sources->reject(fn (AcademicSource $source) => $source->curriculumImports
            ->contains(fn (CurriculumImport $import) => $import->import_type === 'standards'
                && $this->importContextMatches($import, $subject->id, $schoolYearId, $gradeLevelId)));
        $candidates = $pacingSources->flatMap(fn (AcademicSource $source) => $source->curriculumImports
            ->filter(fn (CurriculumImport $import) => $import->import_type !== 'standards')
            ->filter(fn (CurriculumImport $import) => $this->importContextMatches($import, $subject->id, $schoolYearId, $gradeLevelId))
            ->map(fn (CurriculumImport $import) => ['source' => $source, 'import' => $import]));
        $selected = $this->newestImport($candidates);
        $source = $selected['source'] ?? $pacingSources->first();
        /** @var CurriculumImport|null $import */
        $import = $selected['import'] ?? null;
        $mapping = $import?->packageCourse;
        $package = $mapping?->curriculumPackage;
        $targetIsValid = $import ? $this->importTargetIsValid($import, $subject->id, $gradeLevelId) : false;
        $outlineExists = $import && $targetIsValid && $import->units_count > 0
            && ($import->parser_key === StructuredCustomCurriculumParser::KEY || $import->periods_count > 0);
        $capability = $source ? $this->capabilities->cached($source) : null;
        $supportedParser = $capability?->state === 'supported' && $capability->parserKey && $capability->parserVersion
            ? $this->capabilities->parser($capability) : null;
        $isStandardsDocument = $supportedParser instanceof StandardsDocumentParser;
        $formatProfile = $source?->currentFile ? CurriculumFormatProfile::query()->where('example_academic_source_file_id', $source->currentFile->id)->first() : null;

        $standardsUrl = $approvedStandards ? route('academic.standards-imports.show', [
            'curriculumImport' => $standardsImport->id, 'student_id' => $studentId,
        ]) : null;
        [$state, $label, $action, $url] = match (true) {
            $import?->status === 'approved' && $outlineExists => [
                'outline_approved', 'Curriculum outline approved', 'View curriculum outline',
                route('academic.curriculum.show', $package->id),
            ],
            $import?->status === 'review' => [
                'outline_review', 'Curriculum outline ready for review', 'Review curriculum outline',
                route('academic.curriculum-imports.show', $import->id),
            ],
            in_array($import?->status, ['pending', 'processing'], true) => [
                'outline_processing', 'Curriculum outline processing', 'View import status',
                route('academic.curriculum-imports.show', $import->id),
            ],
            $import !== null => [
                'outline_needs_attention', 'Curriculum outline needs attention', 'Review import issue',
                route('academic.curriculum-imports.show', $import->id),
            ],
            $source?->review_status === 'reviewed' && $capability?->state === 'supported' && $isStandardsDocument => [
                'standards_ready', ($source?->gradeLevel?->name ?? 'Selected grade').' standards detected',
                'Import '.($source?->gradeLevel?->name ?? 'selected grade').' '.$subject->name.' standards',
                route('academic.sources.show', $source->id),
            ],
            $source?->review_status === 'reviewed' && $capability?->state === 'supported' => [
                $approvedStandards ? 'pacing_source_reviewed' : 'source_reviewed',
                $approvedStandards ? 'Pacing source reviewed' : 'Source reviewed', 'Create curriculum outline',
                route('academic.sources.show', $source->id),
            ],
            $source?->review_status === 'reviewed' && $capability?->state === 'unsupported' && $formatProfile?->status === 'draft' => [
                'format_setup_in_progress', 'Document format setup in progress', 'Continue document setup',
                route('academic.curriculum-format-profiles.show', $formatProfile->id),
            ],
            $source?->review_status === 'reviewed' && $capability?->state === 'unsupported' => [
                'format_setup_needed', 'Curriculum outline setup needed', 'Set up document format',
                route('academic.sources.curriculum-format-setup.create', $source->id),
            ],
            $source?->review_status === 'reviewed' && $capability?->state === 'unknown' => [
                'outline_support_unknown', 'Outline support not checked', 'Check outline support',
                route('academic.sources.show', $source->id),
            ],
            $source?->review_status === 'reviewed' => [
                'outline_needs_attention', 'Source needs attention', 'View source',
                route('academic.sources.show', $source->id),
            ],
            $source !== null => [
                $approvedStandards ? 'pacing_source_awaiting_review' : 'source_awaiting_review',
                $approvedStandards ? 'Pacing source awaiting review' : 'Source awaiting review',
                $approvedStandards ? 'Review pacing source' : 'Review source',
                route('academic.sources.show', $source->id),
            ],
            $approvedStandards => [
                'standards_imported_pacing_needed', 'Standards imported · Pacing guide still needed',
                'Add '.$subject->name.' pacing guide',
                route('workspace.curriculum-intake.subject.create', [
                    'student' => $studentId, 'schoolYear' => $schoolYearId, 'subject' => $subject->id, 'intent' => 'pacing',
                ]),
            ],
            $standardsImport?->status === 'review' => [
                'standards_review', ($standardsSource?->gradeLevel?->name ?? 'Selected grade').' standards ready for review', 'Review standards',
                route('academic.standards-imports.show', $standardsImport->id),
            ],
            in_array($standardsImport?->status, ['pending', 'processing'], true) => [
                'standards_processing', 'Standards import processing', 'View standards import',
                route('academic.standards-imports.show', $standardsImport->id),
            ],
            $standardsImport !== null => [
                'standards_needs_attention', 'Standards import needs attention', 'Review standards import',
                route('academic.standards-imports.show', $standardsImport->id),
            ],
            default => [
                'not_started', 'Not started', 'Add curriculum source',
                route('workspace.curriculum-intake.subject.create', [
                    'student' => $studentId, 'schoolYear' => $schoolYearId, 'subject' => $subject->id,
                ]),
            ],
        };

        return [
            'workflow_state' => $state, 'status_label' => $label,
            'primary_action_label' => $action, 'primary_action_url' => $url,
            'secondary_action_label' => $approvedStandards ? 'View imported standards' : null,
            'secondary_action_url' => $standardsUrl,
            'standards_import_id' => $standardsImport?->id,
            'standards_count' => (int) ($standardsImport?->standards_count ?? 0),
            'source_id' => $source?->id, 'source_review_status' => $source?->review_status,
            'curriculum_import_id' => $import?->id, 'curriculum_import_status' => $import?->status,
            'package_id' => $package?->id, 'package_course_id' => $mapping?->id,
            'period_count' => (int) ($import?->periods_count ?? 0),
            'unit_count' => (int) ($import?->units_count ?? 0),
            'assessment_count' => (int) ($import?->assessment_count ?? 0),
            'standard_alignment_count' => (int) ($import?->standard_alignments_count ?? 0),
        ];
    }

    /** @param Collection<int, array{source: AcademicSource, import: CurriculumImport}> $candidates */
    private function newestImport(Collection $candidates): ?array
    {
        return $candidates->sort(function (array $left, array $right): int {
            $date = $right['import']->created_at <=> $left['import']->created_at;

            return $date !== 0 ? $date : ($right['import']->id <=> $left['import']->id);
        })->first();
    }

    private function importContextMatches(CurriculumImport $import, int $subjectId, int $schoolYearId, int $gradeLevelId): bool
    {
        return $import->subject_id === $subjectId
            && $import->school_year_id === $schoolYearId
            && $import->grade_level_id === $gradeLevelId;
    }

    private function importTargetIsValid(CurriculumImport $import, int $subjectId, int $gradeLevelId): bool
    {
        $mapping = $import->packageCourse;
        $package = $mapping?->curriculumPackage;

        return $mapping && $package
            && $package->tenant_id === $import->tenant_id
            && $import->curriculum_package_id === $package->id
            && $import->subject_id === $subjectId
            && $mapping->course?->subject_id === $subjectId
            && ($mapping->grade_level_id === null || $mapping->grade_level_id === $gradeLevelId);
    }
}
