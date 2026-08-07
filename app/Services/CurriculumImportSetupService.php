<?php

namespace App\Services;

use App\Models\AcademicSource;
use App\Models\Course;
use App\Models\CurriculumImport;
use App\Models\CurriculumPackage;
use App\Models\CurriculumPackageCourse;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\StandardsFramework;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CurriculumImportSetupService
{
    public function __construct(
        private CurriculumImportService $imports,
        private CurriculumParserCapabilityService $capabilities,
        private AuditService $audit,
    ) {}

    /**
     * Prove parser support before creating setup records, then resolve the draft target
     * and create the import atomically. The import service reuses the assessed pages.
     */
    public function start(AcademicSource $source, ?int $expectedMappingId = null): CurriculumImport
    {
        $assessment = $this->capabilities->assessForImport($source);
        $this->capabilities->assertCurrentSupported($source, $assessment->capability);

        return DB::transaction(function () use ($source, $expectedMappingId, $assessment): CurriculumImport {
            $source = AcademicSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $source->load(['currentFile', 'schoolYear.academicConfiguration', 'gradeLevel', 'links', 'educationProvider']);
            $this->capabilities->assertCurrentSupported($source, $assessment->capability);

            [$subject, $grade, $providerId, $frameworkId] = $this->context($source);
            $package = $this->package($source, $grade, $providerId, $frameworkId);
            $mapping = $this->mapping($package, $subject, $grade, $providerId, $frameworkId);
            if ($expectedMappingId !== null && $mapping->id !== $expectedMappingId) {
                throw ValidationException::withMessages([
                    'curriculum_package_course_id' => 'The requested target does not match the source context.',
                ]);
            }
            $this->linkPackage($source, $package);

            return $this->imports->start($source, $mapping, $assessment);
        }, 3);
    }

    /** @return array{Subject, GradeLevel, int|null, int} */
    private function context(AcademicSource $source): array
    {
        $subjectIds = $source->links->where('link_type', 'subject')->pluck('link_id')->unique()->values();
        if ($subjectIds->count() !== 1 || ! ($subject = Subject::query()->whereKey($subjectIds->first())->where('status', 'active')->first())) {
            $this->missingSetting();
        }
        $grade = GradeLevel::query()->whereKey($source->grade_level_id)->where('is_active', true)->first();
        $year = $source->schoolYear;
        if (! $grade || ! $year || $year->tenant_id !== $source->tenant_id) {
            $this->missingSetting();
        }

        $configuration = $year->academicConfiguration;
        $providerId = $source->education_provider_id ?: $configuration?->education_provider_id;
        if ($source->education_provider_id && $configuration?->education_provider_id
            && $source->education_provider_id !== $configuration->education_provider_id) {
            throw ValidationException::withMessages(['source' => 'The source provider does not match this school year. Update the source details before creating an outline.']);
        }
        if ($providerId && ! EducationProvider::query()->whereKey($providerId)->where('status', 'active')->exists()) {
            $this->missingSetting();
        }

        $linkedFrameworkIds = $source->links->where('link_type', 'standards_framework')->pluck('link_id')->unique()->values();
        if ($linkedFrameworkIds->count() > 1) {
            $this->missingSetting();
        }
        $frameworkId = $linkedFrameworkIds->first() ?: $configuration?->standards_framework_id;
        if (! $frameworkId || ! StandardsFramework::query()->whereKey($frameworkId)->where('status', 'active')->exists()) {
            $this->missingSetting();
        }
        if ($linkedFrameworkIds->isNotEmpty() && $configuration?->standards_framework_id
            && (int) $linkedFrameworkIds->first() !== $configuration->standards_framework_id) {
            throw ValidationException::withMessages(['source' => 'The source standards framework does not match this school year. Update the source details before creating an outline.']);
        }

        return [$subject, $grade, $providerId ? (int) $providerId : null, (int) $frameworkId];
    }

    private function package(
        AcademicSource $source,
        GradeLevel $grade,
        ?int $providerId,
        int $frameworkId,
    ): CurriculumPackage {
        $version = $source->schoolYear->name ?: $source->academic_year_label ?: $source->version_label;
        if (! $version) {
            $this->missingSetting();
        }

        // Serialize this tenant/version naming namespace before matching or creating.
        CurriculumPackage::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('version_label', $version)
            ->lockForUpdate()->get(['id']);

        $linkedIds = $source->links->where('link_type', 'curriculum_package')->pluck('link_id')->unique();
        $query = CurriculumPackage::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('status', 'draft')
            ->where('version_label', $version)
            ->where('standards_framework_id', $frameworkId)
            ->when($providerId, fn ($builder) => $builder->where('education_provider_id', $providerId), fn ($builder) => $builder->whereNull('education_provider_id'));

        $package = (clone $query)->when($linkedIds->isNotEmpty(), fn ($builder) => $builder->whereIn('id', $linkedIds))
            ->orderBy('id')->lockForUpdate()->first()
            ?? (clone $query)->orderBy('id')->lockForUpdate()->first();
        if ($package) {
            return $package;
        }

        $provider = $providerId ? EducationProvider::query()->find($providerId) : null;
        $baseName = trim(($provider?->short_name ?: $provider?->name ?: 'Custom').' '.$grade->name.' Curriculum');
        $name = $baseName;
        for ($suffix = 2; CurriculumPackage::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('version_label', $version)
            ->where('name', $name)->exists(); $suffix++) {
            $name = $baseName.' '.$suffix;
        }
        $package = CurriculumPackage::create([
            'education_provider_id' => $providerId,
            'standards_framework_id' => $frameworkId,
            'name' => $name,
            'version_label' => $version,
            'description' => 'Draft curriculum outline created from reviewed source: '.$source->title.'.',
            'status' => 'draft',
        ]);
        $this->audit->record('curriculum-package.created', $package, [], $package->toArray());

        return $package;
    }

    private function mapping(
        CurriculumPackage $package,
        Subject $subject,
        GradeLevel $grade,
        ?int $providerId,
        int $frameworkId,
    ): CurriculumPackageCourse {
        // Course codes are tenant-unique; lock the tenant namespace before resolving one.
        Course::query()->where('tenant_id', $package->tenant_id)->lockForUpdate()->get(['id']);
        $existing = CurriculumPackageCourse::query()
            ->where('curriculum_package_id', $package->id)
            ->where('grade_context_key', 'grade:'.$grade->id)
            ->whereHas('course', fn ($query) => $this->compatibleCourseQuery($query, $subject, $grade, $providerId, $frameworkId))
            ->orderBy('id')->lockForUpdate()->first();
        if ($existing) {
            return $existing->load(['curriculumPackage', 'course.subject', 'course.minimumGradeLevel', 'course.maximumGradeLevel']);
        }

        $course = Course::query()
            ->where('tenant_id', $package->tenant_id)
            ->tap(fn ($query) => $this->compatibleCourseQuery($query, $subject, $grade, $providerId, $frameworkId))
            ->orderByRaw('CASE WHEN minimum_grade_level_id = ? AND maximum_grade_level_id = ? THEN 0 ELSE 1 END', [$grade->id, $grade->id])
            ->orderBy('id')->lockForUpdate()->first();
        if (! $course) {
            $baseCode = Str::upper(Str::slug(($subject->code ?: $subject->name).'-'.$grade->code, '-'));
            $code = $baseCode;
            for ($suffix = 2; Course::query()->where('tenant_id', $package->tenant_id)->where('code', $code)->exists(); $suffix++) {
                $code = $baseCode.'-'.$suffix;
            }
            $course = Course::create([
                'subject_id' => $subject->id,
                'standards_framework_id' => $frameworkId,
                'education_provider_id' => $providerId,
                'name' => $grade->name.' '.$subject->name,
                'code' => $code,
                'description' => 'Draft course created for a reviewed curriculum outline source.',
                'minimum_grade_level_id' => $grade->id,
                'maximum_grade_level_id' => $grade->id,
                'status' => 'draft',
            ]);
            $this->audit->record('course.created', $course, [], $course->toArray());
        }

        $mapping = CurriculumPackageCourse::create([
            'curriculum_package_id' => $package->id,
            'course_id' => $course->id,
            'grade_level_id' => $grade->id,
            'sort_order' => ((int) $package->courseMappings()->max('sort_order')) + 1,
            'required' => true,
        ]);
        $this->audit->record('curriculum-package.course-added', $mapping, [], $mapping->toArray());

        return $mapping->load(['curriculumPackage', 'course.subject', 'course.minimumGradeLevel', 'course.maximumGradeLevel']);
    }

    private function compatibleCourseQuery($query, Subject $subject, GradeLevel $grade, ?int $providerId, int $frameworkId): void
    {
        $query->where('subject_id', $subject->id)
            ->whereIn('status', ['draft', 'active'])
            ->where('standards_framework_id', $frameworkId)
            ->when($providerId, fn ($builder) => $builder->where('education_provider_id', $providerId), fn ($builder) => $builder->whereNull('education_provider_id'))
            ->whereHas('minimumGradeLevel', fn ($builder) => $builder->where('sort_order', '<=', $grade->sort_order))
            ->whereHas('maximumGradeLevel', fn ($builder) => $builder->where('sort_order', '>=', $grade->sort_order));
    }

    private function linkPackage(AcademicSource $source, CurriculumPackage $package): void
    {
        $link = $source->links()->firstOrCreate([
            'link_type' => 'curriculum_package',
            'link_id' => $package->id,
        ]);
        if ($link->wasRecentlyCreated) {
            $this->audit->record('academic-source.linked', $link, [], $link->toArray());
        }
    }

    private function missingSetting(): never
    {
        throw ValidationException::withMessages([
            'source' => 'We need one missing academic setting before the outline can be created. Edit the source details or academic year settings, then try again.',
        ]);
    }
}
