<?php

namespace App\Services;

use App\Data\CurriculumCapabilityAssessment;
use App\Contracts\StandardsDocumentParser;
use App\Models\AcademicSource;
use App\Models\AcademicSourceFile;
use App\Models\CurriculumImport;
use App\Models\CurriculumImportProposal;
use App\Models\CurriculumPackageCourse;
use App\Models\CurriculumPeriod;
use App\Models\CurriculumUnit;
use App\Models\CurriculumUnitComponent;
use App\Models\CurriculumUnitStandardAlignment;
use App\Models\Standard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CurriculumImportService
{
    public const UNIT_TYPES = ['instructional', 'review', 'transition', 'assessment'];
    public const COMPONENT_TYPES = [
        'strand', 'module', 'genre', 'skill', 'conventions', 'foundational_skill',
        'handwriting', 'integrated_subject', 'assessment_support', 'revising', 'concept', 'practice', 'investigation', 'resource', 'other',
    ];

    public function __construct(
        private CurriculumParserCapabilityService $capabilities,
        private AuditService $audit,
    ) {}

    public function start(AcademicSource $source, CurriculumPackageCourse $mapping, ?CurriculumCapabilityAssessment $assessment = null): CurriculumImport
    {
        $source->loadMissing(['currentFile', 'schoolYear', 'gradeLevel', 'links']);
        $mapping->loadMissing(['curriculumPackage', 'course.subject', 'course.minimumGradeLevel', 'course.maximumGradeLevel']);
        $this->assertEligibleSource($source);
        $frameworkId = $this->assertCompatible($source, $mapping);
        $file = $source->currentFile;
        $assessment ??= $this->capabilities->assessForImport($source);
        $this->capabilities->assertCurrentSupported($source, $assessment->capability);
        $parser = $this->capabilities->parser($assessment->capability);
        if ($parser instanceof StandardsDocumentParser) {
            throw ValidationException::withMessages(['source' => 'Use the standards import workflow for this multi-grade standards document.']);
        }
        if ($parser->recognitionScore($assessment->pages, $source) <= 0) {
            throw ValidationException::withMessages(['source' => 'Outline support must be checked again before extraction.']);
        }

        $import = DB::transaction(function () use ($source, $file, $mapping, $frameworkId): CurriculumImport {
            AcademicSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            AcademicSourceFile::query()->whereKey($file->id)->lockForUpdate()->firstOrFail();
            CurriculumPackageCourse::query()->whereKey($mapping->id)->lockForUpdate()->firstOrFail();
            $existing = CurriculumImport::query()
                ->where('academic_source_file_id', $file->id)
                ->where('curriculum_package_course_id', $mapping->id)
                ->lockForUpdate()->first();
            if ($existing && $existing->status !== 'failed') {
                return $existing;
            }
            if ($existing) {
                $existing->proposals()->delete();
                $existing->update(['status' => 'processing', 'diagnostic' => null, 'started_at' => now(), 'completed_at' => null]);
                $source->update(['processing_status' => 'processing']);
                return $existing;
            }

            $created = CurriculumImport::create([
                'academic_source_id' => $source->id, 'academic_source_file_id' => $file->id,
                'curriculum_package_id' => $mapping->curriculum_package_id,
                'curriculum_package_course_id' => $mapping->id,
                'subject_id' => $mapping->course->subject_id,
                'grade_level_id' => $source->grade_level_id,
                'school_year_id' => $source->school_year_id,
                'standards_framework_id' => $frameworkId,
                'created_by_user_id' => auth()->id(), 'status' => 'processing',
                'parser_key' => 'pending', 'parser_version' => 'pending',
                'extraction_method' => 'pdf_positioned_text', 'started_at' => now(),
            ]);
            $source->update(['processing_status' => 'processing']);

            return $created;
        });
        if ($import->status !== 'processing') {
            return $import->fresh('proposals');
        }

        try {
            $pages = $assessment->pages;
            if (collect($pages)->every(fn (array $page) => trim($page['text']) === '')) {
                throw new \RuntimeException('The curriculum PDF has no usable text layer. OCR is not enabled.');
            }
            $result = $parser->parse($pages, $source);
            if (collect($result->proposals)->where('proposalType', 'period')->isEmpty()) {
                throw new \RuntimeException('No reporting periods were recognized in this curriculum document.');
            }

            DB::transaction(function () use ($import, $source, $parser, $result): void {
                $locked = CurriculumImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
                $parentIds = [];
                foreach ($result->proposals as $proposalData) {
                    $values = $proposalData->toArray();
                    $values['parent_proposal_id'] = $proposalData->parentKey ? ($parentIds[$proposalData->parentKey] ?? null) : null;
                    $proposal = $locked->proposals()->create($values);
                    $parentIds[$proposalData->key] = $proposal->id;
                }
                $locked->update([
                    'status' => 'review', 'parser_key' => $parser->key(), 'parser_version' => $parser->version(),
                    'extraction_method' => $parser->extractionMethod(), 'source_title' => $result->title,
                    'source_revision_date' => $result->revisionDate, 'diagnostic' => $result->diagnostic,
                    'completed_at' => now(),
                ]);
                $source->update(['processing_status' => 'completed']);
                $this->audit->record('curriculum-import.extracted', $locked, [], $locked->fresh()->toArray());
            });
        } catch (Throwable $exception) {
            Log::warning('Curriculum PDF extraction failed.', ['curriculum_import_id' => $import->id, 'exception' => $exception]);
            $safe = [
                'The curriculum PDF has no usable text layer. OCR is not enabled.',
                'No reporting periods were recognized in this curriculum document.',
                'No curriculum parser recognizes this document format yet. The source and PDF were not changed.',
                'The PDF could not be read. Confirm that it is a valid, unencrypted PDF.',
            ];
            $message = in_array($exception->getMessage(), $safe, true)
                ? $exception->getMessage()
                : 'Curriculum extraction failed. Verify the PDF and try again; technical details were recorded.';
            $import->update(['status' => 'failed', 'diagnostic' => $message, 'completed_at' => now()]);
            $source->update(['processing_status' => 'failed']);
            throw ValidationException::withMessages(['source' => $message]);
        }

        return $import->fresh('proposals');
    }

    /** @param array<int|string, array<string, mixed>> $submitted */
    public function bulkUpdate(CurriculumImport $import, array $submitted): CurriculumImport
    {
        return DB::transaction(function () use ($import, $submitted): CurriculumImport {
            $locked = CurriculumImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'review') {
                throw ValidationException::withMessages(['review' => $locked->status === 'approved'
                    ? 'This curriculum import is approved and read-only.' : 'This curriculum import is no longer editable.']);
            }
            $source = $locked->source()->lockForUpdate()->firstOrFail();
            $file = AcademicSourceFile::query()->whereKey($locked->academic_source_file_id)->lockForUpdate()->firstOrFail();
            $mapping = $locked->packageCourse()->lockForUpdate()->firstOrFail();
            $source->loadMissing(['schoolYear', 'gradeLevel', 'links', 'currentFile']);
            $mapping->load(['curriculumPackage', 'course.subject', 'course.minimumGradeLevel', 'course.maximumGradeLevel']);
            $this->assertReviewableSource($source, 'review');
            $frameworkId = $this->assertCompatible($source, $mapping);
            $this->assertImportContext($locked, $source, $file, $mapping, $frameworkId, 'review');
            $submittedById = collect($submitted)->mapWithKeys(fn (array $row, $key) => [(int) ($row['id'] ?? $key) => $row]);
            $proposals = $locked->proposals()->lockForUpdate()->get()->keyBy('id');
            if ($submittedById->keys()->sort()->values()->all() !== $proposals->keys()->sort()->values()->all()) {
                throw ValidationException::withMessages(['review' => 'The proposal list changed. Reload before saving the complete review.']);
            }
            $this->validateRows($proposals, $submittedById);

            foreach ($submittedById as $id => $values) {
                $proposal = $proposals->get($id);
                $before = $proposal->toArray();
                $proposal->fill([
                    'parent_proposal_id' => $proposal->proposal_type === 'period' ? null : (int) $values['parent_proposal_id'],
                    'included' => (bool) $values['included'], 'sequence' => (int) $values['sequence'],
                    'name' => trim($values['name']),
                    'description' => $proposal->proposal_type === 'component' ? trim((string) ($values['description'] ?? '')) ?: null : null,
                    'summary' => in_array($proposal->proposal_type, ['unit', 'assessment'], true) ? trim((string) ($values['summary'] ?? '')) ?: null : null,
                    'planned_start_date' => ($values['planned_start_date'] ?? null) ?: null,
                    'planned_end_date' => ($values['planned_end_date'] ?? null) ?: null,
                    'estimated_days' => ($values['estimated_days'] ?? null) === null || $values['estimated_days'] === '' ? null : (int) $values['estimated_days'],
                    'unit_type' => in_array($proposal->proposal_type, ['unit', 'assessment'], true) ? $values['unit_type'] : null,
                    'component_type' => $proposal->proposal_type === 'component' ? $values['component_type'] : null,
                    'reporting_period' => $proposal->proposal_type === 'period' ? trim($values['name']) : $proposal->reporting_period,
                    'standard_codes' => in_array($proposal->proposal_type, ['unit', 'assessment'], true) ? $this->normalizeCodes($values['standard_codes'] ?? []) : [],
                ]);
                if ($proposal->isDirty()) {
                    $proposal->manually_edited = true;
                    $proposal->save();
                    $this->audit->record('curriculum-import.proposal-updated', $proposal, $before, $proposal->fresh()->toArray());
                }
            }
            $locked->increment('review_version');
            $this->audit->record('curriculum-import.review-saved', $locked, [], $locked->fresh()->toArray());

            return $locked->fresh('proposals');
        });
    }

    public function reextract(CurriculumImport $import): CurriculumImport
    {
        if ($import->parser_key !== CfisdGrade5ElarParentYearAtGlanceParser::KEY) {
            throw ValidationException::withMessages(['reextract' => 'Re-extraction is available only for the supported ELAR outline workflow.']);
        }
        $import->loadMissing(['source.currentFile', 'source.schoolYear', 'source.gradeLevel', 'source.links']);
        if ($import->status !== 'review') {
            throw ValidationException::withMessages(['reextract' => $import->status === 'approved'
                ? 'Approved curriculum history cannot be re-extracted. Start a new version from the source instead.'
                : 'Only an unapproved import awaiting review can be re-extracted.']);
        }
        $source = $import->source;
        $this->assertReviewableSource($source, 'reextract');
        $assessment = $this->capabilities->assessForImport($source);
        $this->capabilities->assertCurrentSupported($source, $assessment->capability);
        $parser = $this->capabilities->parser($assessment->capability);
        if ($parser->recognitionScore($assessment->pages, $source) <= 0) {
            throw ValidationException::withMessages(['reextract' => 'Outline support must be checked again before re-extraction.']);
        }
        $result = $parser->parse($assessment->pages, $source);
        if (collect($result->proposals)->where('proposalType', 'period')->isEmpty()) {
            throw ValidationException::withMessages(['reextract' => 'No reporting periods were recognized; the existing review was preserved.']);
        }

        return DB::transaction(function () use ($import, $parser, $result): CurriculumImport {
            $locked = CurriculumImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'review') throw ValidationException::withMessages(['reextract' => 'This import is no longer awaiting review.']);
            $source = $locked->source()->lockForUpdate()->firstOrFail();
            $file = AcademicSourceFile::query()->whereKey($locked->academic_source_file_id)->lockForUpdate()->firstOrFail();
            $mapping = $locked->packageCourse()->lockForUpdate()->firstOrFail();
            $source->loadMissing(['schoolYear', 'gradeLevel', 'links', 'currentFile']);
            $mapping->load(['curriculumPackage', 'course.subject', 'course.minimumGradeLevel', 'course.maximumGradeLevel']);
            $this->assertReviewableSource($source, 'reextract');
            $frameworkId = $this->assertCompatible($source, $mapping);
            $this->assertImportContext($locked, $source, $file, $mapping, $frameworkId, 'reextract');
            if ($source->currentFile?->id !== $file->id) throw ValidationException::withMessages(['reextract' => 'The source file changed. Start a new import for the current file.']);

            $generation = ((int) $locked->proposals()->max('extraction_generation')) + 1;
            $locked->proposals()->update(['superseded_at' => now()]);
            $this->storeProposals($locked, $result->proposals, $generation);
            $locked->update([
                'parser_key' => $parser->key(), 'parser_version' => $parser->version(),
                'extraction_method' => $parser->extractionMethod(), 'source_title' => $result->title,
                'source_revision_date' => $result->revisionDate, 'diagnostic' => $result->diagnostic,
                'completed_at' => now(), 'review_version' => $locked->review_version + 1,
            ]);
            $this->audit->record('curriculum-import.reextracted', $locked, [], $locked->fresh()->toArray());
            return $locked->fresh('proposals');
        });
    }

    public function approve(CurriculumImport $import, int $reviewVersion): CurriculumImport
    {
        return DB::transaction(function () use ($import, $reviewVersion): CurriculumImport {
            $locked = CurriculumImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'review') {
                throw ValidationException::withMessages(['approval' => $locked->status === 'approved'
                    ? 'This curriculum import has already been approved.' : 'Only an import awaiting review can be approved.']);
            }
            if ($locked->review_version !== $reviewVersion) {
                throw ValidationException::withMessages(['approval' => 'The saved review changed. Reload before approval.']);
            }
            if (str_starts_with($locked->parser_key, 'format-profile-') && $locked->review_version < 1) {
                throw ValidationException::withMessages(['approval' => 'Save a complete human review before approving an outline created by a declarative format profile.']);
            }
            $source = $locked->source()->lockForUpdate()->firstOrFail();
            $file = AcademicSourceFile::query()->whereKey($locked->academic_source_file_id)->lockForUpdate()->firstOrFail();
            $mapping = $locked->packageCourse()->lockForUpdate()->firstOrFail();
            $mapping->load(['curriculumPackage', 'course.subject', 'course.minimumGradeLevel', 'course.maximumGradeLevel']);
            $source->loadMissing(['schoolYear', 'gradeLevel', 'links', 'currentFile']);
            $this->assertReviewableSource($source, 'approval');
            $frameworkId = $this->assertCompatible($source, $mapping);
            $this->assertImportContext($locked, $source, $file, $mapping, $frameworkId, 'approval');
            if ($mapping->curriculumPackage->status !== 'draft') {
                throw ValidationException::withMessages(['approval' => 'Curriculum outlines may be approved only into a draft package.']);
            }
            CurriculumPeriod::query()->where('curriculum_package_course_id', $mapping->id)->lockForUpdate()->get();
            if (CurriculumPeriod::query()->where('curriculum_package_course_id', $mapping->id)->exists()) {
                throw ValidationException::withMessages(['approval' => 'This package course already has an outline. Choose an empty draft target.']);
            }
            $proposals = $locked->proposals()->lockForUpdate()->get()->keyBy('id');
            $submitted = $proposals->mapWithKeys(fn ($row) => [$row->id => [
                'id' => $row->id, 'parent_proposal_id' => $row->parent_proposal_id,
                'included' => $row->included, 'sequence' => $row->sequence, 'name' => $row->name,
                'planned_start_date' => $row->planned_start_date?->format('Y-m-d'),
                'planned_end_date' => $row->planned_end_date?->format('Y-m-d'),
                'estimated_days' => $row->estimated_days, 'unit_type' => $row->unit_type,
                'component_type' => $row->component_type, 'description' => $row->description,
                'summary' => $row->summary,
                'standard_codes' => $row->standard_codes ?? [],
            ]]);
            $this->validateRows($proposals, $submitted);

            $periods = [];
            foreach ($proposals->where('proposal_type', 'period')->where('included', true)->sortBy('sequence') as $proposal) {
                $period = CurriculumPeriod::create([
                    'curriculum_package_course_id' => $mapping->id, 'name' => $proposal->name,
                    'sequence' => $proposal->sequence, 'planned_start_date' => $proposal->planned_start_date,
                    'planned_end_date' => $proposal->planned_end_date, 'period_type' => 'reporting_period',
                    'status' => 'draft', ...$this->provenance($locked, $proposal),
                ]);
                $periods[$proposal->id] = $period;
                $this->audit->record('curriculum-period.imported', $period, [], $period->toArray());
            }
            $units = [];
            foreach ($proposals->whereIn('proposal_type', ['unit', 'assessment'])->where('included', true)->sortBy('sequence') as $proposal) {
                $period = $periods[$proposal->parent_proposal_id] ?? null;
                if (! $period) {
                    throw ValidationException::withMessages(["proposals.{$proposal->id}.parent_proposal_id" => 'Select an included reporting period.']);
                }
                $unit = CurriculumUnit::create([
                    'curriculum_period_id' => $period->id, 'curriculum_package_course_id' => $mapping->id,
                    'name' => $proposal->name, 'summary' => $proposal->summary, 'sequence' => $proposal->sequence,
                    'planned_start_date' => $proposal->planned_start_date, 'planned_end_date' => $proposal->planned_end_date,
                    'estimated_days' => $proposal->estimated_days, 'unit_type' => $proposal->unit_type,
                    'included' => true, ...$this->provenance($locked, $proposal),
                ]);
                $this->audit->record('curriculum-unit.imported', $unit, [], $unit->toArray());
                $units[$proposal->id] = $unit;
                foreach ($this->normalizeCodes($proposal->standard_codes ?? []) as $code) {
                    $normalizedCode = $this->normalizedCode($code);
                    $standardId = Standard::query()->where('standards_framework_id', $frameworkId)
                        ->where('subject_id', $locked->subject_id)->where('grade_level_id', $locked->grade_level_id)
                        ->where('normalized_code', $normalizedCode)->whereIn('record_type', ['standard', 'student_expectation'])
                        ->latest('id')->value('id');
                    $alignment = CurriculumUnitStandardAlignment::create([
                        'curriculum_unit_id' => $unit->id, 'standards_framework_id' => $frameworkId,
                        'standard_id' => $standardId, 'standard_code' => $code, 'normalized_code' => $normalizedCode,
                        ...$this->provenance($locked, $proposal),
                    ]);
                    $this->audit->record('curriculum-unit.standard-aligned', $alignment, [], $alignment->toArray());
                }
            }
            $componentRecords = [];
            $pendingComponents = $proposals->where('proposal_type', 'component')->where('included', true)->values();
            while ($pendingComponents->isNotEmpty()) {
                $remaining = collect();
                $createdThisPass = 0;
                foreach ($pendingComponents as $proposal) {
                    $parentProposal = $proposals->get($proposal->parent_proposal_id);
                    $unit = $parentProposal && in_array($parentProposal->proposal_type, ['unit', 'assessment'], true)
                        ? ($units[$parentProposal->id] ?? null)
                        : ($componentRecords[$parentProposal?->id] ?? null)?->unit;
                    if (! $unit || ($parentProposal?->proposal_type === 'component' && ! isset($componentRecords[$parentProposal->id]))) {
                        $remaining->push($proposal);
                        continue;
                    }
                    $parentComponent = $parentProposal?->proposal_type === 'component' ? $componentRecords[$parentProposal->id] : null;
                    $component = CurriculumUnitComponent::create([
                        'curriculum_unit_id' => $unit->id, 'parent_component_id' => $parentComponent?->id,
                        'component_type' => $proposal->component_type, 'name' => $proposal->name,
                        'description' => $proposal->description, 'sequence' => $proposal->sequence,
                        'planned_start_date' => $proposal->planned_start_date, 'planned_end_date' => $proposal->planned_end_date,
                        'metadata' => $proposal->parser_metadata, ...$this->provenance($locked, $proposal),
                    ]);
                    $component->setRelation('unit', $unit);
                    $componentRecords[$proposal->id] = $component;
                    $createdThisPass++;
                    $this->audit->record('curriculum-unit.component-imported', $component, [], $component->toArray());
                }
                if ($createdThisPass === 0 && $remaining->isNotEmpty()) {
                    throw ValidationException::withMessages(['review' => 'A component parent could not be materialized. Reload and review the component hierarchy.']);
                }
                $pendingComponents = $remaining;
            }
            $locked->update(['status' => 'approved', 'approved_by_user_id' => auth()->id(), 'approved_at' => now()]);
            $source->update(['processing_status' => 'completed']);
            $this->audit->record('curriculum-import.approved', $locked, [], $locked->fresh()->toArray());

            return $locked->fresh(['periods.units.standardAlignments', 'periods.units.allComponents', 'proposals']);
        });
    }

    private function assertEligibleSource(AcademicSource $source): void
    {
        if ($source->archived_at || $source->review_status !== 'reviewed'
            || ! in_array($source->source_category, ['curriculum', 'pacing', 'scope_and_sequence'], true)) {
            throw ValidationException::withMessages(['source' => 'Use a reviewed, non-archived curriculum source for extraction.']);
        }
        if ($source->source_kind !== 'upload' || ! $source->currentFile
            || $source->currentFile->mime_type !== 'application/pdf' || $source->currentFile->extension !== 'pdf') {
            throw ValidationException::withMessages(['source' => 'The current curriculum source file must be a validated PDF.']);
        }
        if (! $source->school_year_id || ! $source->grade_level_id) {
            throw ValidationException::withMessages(['source' => 'Assign the curriculum source to a school year and grade before extraction.']);
        }
    }

    private function assertReviewableSource(AcademicSource $source, string $errorKey): void
    {
        if ($source->archived_at || $source->review_status !== 'reviewed'
            || ! in_array($source->source_category, ['curriculum', 'pacing', 'scope_and_sequence'], true)) {
            throw ValidationException::withMessages([$errorKey => 'The curriculum source must remain reviewed and non-archived.']);
        }
    }

    private function assertImportContext(
        CurriculumImport $import,
        AcademicSource $source,
        AcademicSourceFile $file,
        CurriculumPackageCourse $mapping,
        int $frameworkId,
        string $errorKey,
    ): void {
        if ($import->curriculum_package_id !== $mapping->curriculum_package_id
            || $import->subject_id !== $mapping->course->subject_id
            || $import->academic_source_id !== $source->id
            || $file->academic_source_id !== $source->id
            || $import->grade_level_id !== $source->grade_level_id
            || $import->school_year_id !== $source->school_year_id
            || $import->standards_framework_id !== $frameworkId) {
            throw ValidationException::withMessages([$errorKey => 'The saved import context no longer matches its source and target.']);
        }
    }

    private function assertCompatible(AcademicSource $source, CurriculumPackageCourse $mapping): int
    {
        $package = $mapping->curriculumPackage;
        $course = $mapping->course;
        $subjectId = $source->links->firstWhere('link_type', 'subject')?->link_id;
        if ($package->status !== 'draft' || $package->tenant_id !== $source->tenant_id) {
            throw ValidationException::withMessages(['curriculum_package_course_id' => 'Choose a tenant-owned draft curriculum package.']);
        }
        if (! $subjectId || $course->subject_id !== $subjectId) {
            throw ValidationException::withMessages(['curriculum_package_course_id' => 'The mapped course subject does not match this source.']);
        }
        if ($mapping->grade_level_id && $mapping->grade_level_id !== $source->grade_level_id) {
            throw ValidationException::withMessages(['curriculum_package_course_id' => 'The package course grade does not match this source.']);
        }
        $gradeOrder = $source->gradeLevel?->sort_order;
        if ($gradeOrder !== null && (($course->minimumGradeLevel && $gradeOrder < $course->minimumGradeLevel->sort_order)
            || ($course->maximumGradeLevel && $gradeOrder > $course->maximumGradeLevel->sort_order))) {
            throw ValidationException::withMessages(['curriculum_package_course_id' => 'The source grade is outside the course grade range.']);
        }
        foreach ([$package->education_provider_id, $course->education_provider_id] as $providerId) {
            if ($source->education_provider_id && $providerId && $source->education_provider_id !== $providerId) {
                throw ValidationException::withMessages(['curriculum_package_course_id' => 'The package or course provider does not match this source.']);
            }
        }
        if ($package->education_provider_id && $course->education_provider_id
            && $package->education_provider_id !== $course->education_provider_id) {
            throw ValidationException::withMessages(['curriculum_package_course_id' => 'The package and course providers do not match.']);
        }
        $frameworkId = $course->standards_framework_id ?: $package->standards_framework_id;
        if (! $frameworkId || ($course->standards_framework_id && $package->standards_framework_id
            && $course->standards_framework_id !== $package->standards_framework_id)) {
            throw ValidationException::withMessages(['curriculum_package_course_id' => 'Choose a package course with one compatible standards framework.']);
        }
        $sourceFrameworkId = $source->links->firstWhere('link_type', 'standards_framework')?->link_id;
        if ($sourceFrameworkId && $sourceFrameworkId !== $frameworkId) {
            throw ValidationException::withMessages(['curriculum_package_course_id' => 'The target standards framework does not match this source.']);
        }

        return $frameworkId;
    }

    private function validateRows($proposals, $submitted): void
    {
        $errors = [];
        $periodIds = $proposals->where('proposal_type', 'period')->keys();
        $periodSequences = [];
        $unitSequences = [];
        $componentSequences = [];
        foreach ($submitted as $id => $row) {
            $proposal = $proposals->get((int) $id);
            if (! $proposal) { $errors["proposals.{$id}.id"] = 'This proposal is no longer available.'; continue; }
            if (! trim((string) ($row['name'] ?? ''))) $errors["proposals.{$id}.name"] = 'Enter a name.';
            $start = ($row['planned_start_date'] ?? null) ?: null;
            $end = ($row['planned_end_date'] ?? null) ?: null;
            if ($start && $end && $end < $start) $errors["proposals.{$id}.planned_end_date"] = 'The end date must be on or after the start date.';
            $sequence = (int) ($row['sequence'] ?? 0);
            if ($sequence < 1 || $sequence > 65535) $errors["proposals.{$id}.sequence"] = 'Sequence must be between 1 and 65535.';
            if (! ($row['included'] ?? false)) continue;
            if ($proposal->proposal_type === 'period') {
                if (isset($periodSequences[$sequence])) $errors["proposals.{$id}.sequence"] = 'Included reporting-period sequences must be unique.';
                $periodSequences[$sequence] = true;
                continue;
            }
            $parentId = (int) ($row['parent_proposal_id'] ?? 0);
            if ($proposal->proposal_type === 'component') {
                $parent = $proposals->get($parentId);
                if (! $parent || ! in_array($parent->proposal_type, ['unit', 'assessment', 'component'], true)
                    || ! ($submitted[$parentId]['included'] ?? false) || $parentId === (int) $id) {
                    $errors["proposals.{$id}.parent_proposal_id"] = 'Select an included unit or component parent.';
                } elseif ($this->componentCycle((int) $id, $parentId, $proposals, $submitted)) {
                    $errors["proposals.{$id}.parent_proposal_id"] = 'Component hierarchy cannot contain a cycle.';
                }
                if (! in_array($row['component_type'] ?? null, self::COMPONENT_TYPES, true)) {
                    $errors["proposals.{$id}.component_type"] = 'Select a valid component type.';
                }
                if (isset($componentSequences[$parentId][$sequence])) {
                    $errors["proposals.{$id}.sequence"] = 'Included component sequences must be unique within their parent.';
                }
                $componentSequences[$parentId][$sequence] = true;
            } else {
                if (! $periodIds->contains($parentId) || ! ($submitted[$parentId]['included'] ?? false)) {
                    $errors["proposals.{$id}.parent_proposal_id"] = 'Select an included reporting period.';
                }
                if (! in_array($row['unit_type'] ?? null, self::UNIT_TYPES, true)) $errors["proposals.{$id}.unit_type"] = 'Select a valid unit type.';
                if (isset($unitSequences[$sequence])) $errors["proposals.{$id}.sequence"] = 'Included unit sequences must be unique.';
                $unitSequences[$sequence] = true;
                $days = $row['estimated_days'] ?? null;
                if ($days !== null && $days !== '' && ((int) $days < 1 || (int) $days > 366)) $errors["proposals.{$id}.estimated_days"] = 'Estimated days must be between 1 and 366.';
            }
        }
        if (! collect($submitted)->contains(fn ($row, $id) => ($row['included'] ?? false) && $proposals->get((int) $id)?->proposal_type === 'period')) {
            $errors['review'] = 'Include at least one reporting period.';
        }
        if ($errors) throw ValidationException::withMessages(['review' => $errors['review'] ?? 'Resolve the highlighted curriculum proposals.', ...$errors]);
    }

    private function componentCycle(int $id, int $parentId, $proposals, $submitted): bool
    {
        $seen = [$id => true];
        while ($parent = $proposals->get($parentId)) {
            if (isset($seen[$parentId])) return true;
            $seen[$parentId] = true;
            if ($parent->proposal_type !== 'component') return ! in_array($parent->proposal_type, ['unit', 'assessment'], true);
            $parentId = (int) ($submitted[$parentId]['parent_proposal_id'] ?? 0);
        }
        return true;
    }

    private function normalizeCodes(array|string $codes): array
    {
        if (is_string($codes)) $codes = preg_split('/[,;\n]+/', $codes) ?: [];
        return collect($codes)->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn ($code) => $code !== '')->unique()->values()->all();
    }

    private function normalizedCode(string $code): string { return strtoupper(preg_replace('/[^A-Z0-9.]/i', '', $code) ?? $code); }

    private function provenance(CurriculumImport $import, CurriculumImportProposal $proposal): array
    {
        return [
            'academic_source_id' => $import->academic_source_id,
            'academic_source_file_id' => $import->academic_source_file_id,
            'curriculum_import_id' => $import->id,
            'curriculum_import_proposal_id' => $proposal->id,
            'source_page' => $proposal->source_page, 'source_raw_text' => $proposal->raw_text,
            'parser_key' => $import->parser_key, 'parser_version' => $import->parser_version,
            'source_confidence' => $proposal->confidence, 'source_note' => $proposal->parser_note,
        ];
    }

    private function storeProposals(CurriculumImport $import, array $proposalDataRows, int $generation = 1): void
    {
        $parentIds = [];
        foreach ($proposalDataRows as $proposalData) {
            $values = $proposalData->toArray();
            $values['extraction_generation'] = $generation;
            $values['parent_proposal_id'] = $proposalData->parentKey ? ($parentIds[$proposalData->parentKey] ?? null) : null;
            $proposal = $import->proposals()->create($values);
            $parentIds[$proposalData->key] = $proposal->id;
        }
    }

}
