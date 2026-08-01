<?php

namespace App\Services;

use App\Models\AcademicSource;
use App\Models\CurriculumPackage;
use App\Models\EducationProvider;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Support\SafeExternalUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class CurriculumIntakeService
{
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
        $overview = $selected ? $this->subjects($subjects, $selected->school_year_id, $selected->grade_level_id) : $subjects->map(fn ($subject) => $this->emptySubject($subject));

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
            'selectedContext' => $selected ? [
                'student_id' => $selected->student_id,
                'student_name' => $selected->student->display_name,
                'school_year_id' => $selected->school_year_id,
                'school_year_name' => $selected->schoolYear->name,
                'grade_level_id' => $selected->grade_level_id,
                'grade_name' => $selected->gradeLevel->name,
            ] : null,
            'providers' => EducationProvider::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'short_name', 'provider_type', 'tenant_id']),
            'subjects' => $overview->values()->all(),
            'permissions' => [
                'create' => Gate::allows('create', AcademicSource::class),
                'review' => app(PermissionService::class)->allows('academic-sources.review'),
                'manage' => app(PermissionService::class)->allows('academic-sources.manage'),
                'create_draft' => app(PermissionService::class)->allows('curriculum.manage'),
                'advanced' => app(PermissionService::class)->allows('advanced-academic.view'),
            ],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function subjects(Collection $subjects, int $schoolYearId, int $gradeLevelId): Collection
    {
        $sources = AcademicSource::query()
            ->whereNull('archived_at')
            ->where('source_category', 'curriculum')
            ->where('school_year_id', $schoolYearId)
            ->where('grade_level_id', $gradeLevelId)
            ->whereHas('links', fn ($query) => $query->where('link_type', 'subject')->whereIn('link_id', $subjects->pluck('id')))
            ->with(['educationProvider:id,name,short_name', 'currentFile', 'links'])
            ->latest('updated_at')
            ->get();
        $packages = CurriculumPackage::query()
            ->whereIn('id', $sources->flatMap(fn ($source) => $source->links->where('link_type', 'curriculum_package')->pluck('link_id'))->unique())
            ->with('courseMappings.course.subject')
            ->get()->keyBy('id');

        return $subjects->map(function ($subject) use ($sources, $packages, $gradeLevelId): array {
            $subjectSources = $sources->filter(fn ($source) => $source->links->contains(fn ($link) => $link->link_type === 'subject' && $link->link_id === $subject->id));
            $sourceItems = $subjectSources->map(fn ($source) => $this->source($source, $packages))->values();
            $linkedPackages = $sourceItems->pluck('draft')->filter();
            $ready = $linkedPackages->contains(function ($package) use ($packages, $subject, $gradeLevelId): bool {
                $model = $packages->get($package['id']);

                return $model?->status === 'active' && $model->courseMappings->contains(fn ($mapping) => $mapping->course?->subject_id === $subject->id
                    && ($mapping->grade_level_id === null || $mapping->grade_level_id === $gradeLevelId));
            });
            $status = match (true) {
                $ready => 'ready',
                $linkedPackages->isNotEmpty() => 'draft_started',
                $subjectSources->contains('review_status', 'reviewed') => 'reviewed',
                $subjectSources->contains('review_status', 'in_review') => 'needs_review',
                $subjectSources->isNotEmpty() => 'source_added',
                default => 'not_started',
            };

            return [
                'id' => $subject->id,
                'name' => $subject->name,
                'code' => $subject->code,
                'status' => $status,
                'status_label' => $this->statusLabel($status),
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
        return ['id' => $subject->id, 'name' => $subject->name, 'code' => $subject->code, 'status' => 'not_started', 'status_label' => 'Not started', 'source_count' => 0, 'sources' => []];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'source_added' => 'Source added',
            'needs_review' => 'Needs review',
            'reviewed' => 'Reviewed',
            'draft_started' => 'Draft curriculum started',
            'ready' => 'Ready for lesson planning',
            default => 'Not started',
        };
    }
}
