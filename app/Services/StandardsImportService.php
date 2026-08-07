<?php

namespace App\Services;

use App\Contracts\StandardsDocumentParser;
use App\Models\AcademicSource;
use App\Models\AcademicSourceFile;
use App\Models\AcademicYearConfiguration;
use App\Models\CurriculumImport;
use App\Models\CurriculumImportProposal;
use App\Models\Standard;
use App\Models\StandardsFramework;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StandardsImportService
{
    public function __construct(
        private CurriculumParserCapabilityService $capabilities,
        private AuditService $audit,
        private StandardsDocumentMetadataNormalizer $metadataNormalizer,
    ) {}

    public function start(AcademicSource $source): CurriculumImport
    {
        $source->loadMissing(['currentFile', 'schoolYear', 'gradeLevel', 'links']);
        $this->assertEligible($source, 'source');
        [$subjectId, $frameworkId] = $this->context($source);
        $assessment = $this->capabilities->assessForImport($source);
        $this->capabilities->assertCurrentSupported($source, $assessment->capability);
        $parser = $this->capabilities->parser($assessment->capability);
        if (! $parser instanceof StandardsDocumentParser || $parser->importType() !== 'standards') {
            throw ValidationException::withMessages(['source' => 'This source is supported as a curriculum outline, not as a standards document.']);
        }
        if (count($parser->matchingSections($assessment->pages, $source)) !== 1) {
            throw ValidationException::withMessages(['source' => 'Exactly one section must match the selected subject and grade.']);
        }
        $file = $source->currentFile;
        $import = DB::transaction(function () use ($source, $file, $subjectId, $frameworkId): CurriculumImport {
            AcademicSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            AcademicSourceFile::query()->whereKey($file->id)->lockForUpdate()->firstOrFail();
            $existing = CurriculumImport::query()->where('academic_source_file_id', $file->id)->where('import_type', 'standards')
                ->where('subject_id', $subjectId)->where('grade_level_id', $source->grade_level_id)
                ->where('standards_framework_id', $frameworkId)->lockForUpdate()->first();
            if ($existing) return $existing;
            $created = CurriculumImport::create([
                'academic_source_id' => $source->id, 'academic_source_file_id' => $file->id,
                'curriculum_package_id' => null, 'curriculum_package_course_id' => null,
                'subject_id' => $subjectId, 'grade_level_id' => $source->grade_level_id,
                'school_year_id' => $source->school_year_id, 'standards_framework_id' => $frameworkId,
                'import_type' => 'standards',
                'import_context_key' => "standards:{$file->id}:{$subjectId}:{$source->grade_level_id}:{$frameworkId}",
                'created_by_user_id' => auth()->id(), 'status' => 'processing',
                'parser_key' => 'pending', 'parser_version' => 'pending', 'extraction_method' => 'pdf_text_sectioned',
                'started_at' => now(),
            ]);
            $source->update(['processing_status' => 'processing']);
            return $created;
        });
        if ($import->status !== 'processing') return $import->fresh('proposals');

        try {
            $result = $parser->parse($assessment->pages, $source);
            $types = collect($result->proposals)->countBy('proposalType');
            if (! $types->get('strand') || ! $types->get('standard')) throw new \RuntimeException('No standards hierarchy was recognized.');
            DB::transaction(function () use ($import, $source, $parser, $result): void {
                $locked = CurriculumImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
                $this->storeProposals($locked, $result->proposals);
                $metadata = $result->metadata;
                $locked->update([
                    'status' => 'review', 'parser_key' => $parser->key(), 'parser_version' => $parser->version(),
                    'extraction_method' => $parser->extractionMethod(), 'source_title' => $result->title,
                    'document_section' => $metadata['section'] ?? null, 'adopted_label' => $metadata['adopted_label'] ?? null,
                    'introduction_text' => $metadata['introduction_text'] ?? null, 'document_metadata' => $metadata,
                    'diagnostic' => $result->diagnostic, 'completed_at' => now(),
                ]);
                $source->update(['processing_status' => 'completed']);
                $this->audit->record('standards-import.extracted', $locked, [], $locked->fresh()->toArray());
            });
        } catch (Throwable $exception) {
            DB::transaction(function () use ($import, $source, $exception): void {
                $locked = CurriculumImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
                $locked->proposals()->delete();
                $locked->update(['status' => 'failed', 'diagnostic' => 'Standards extraction failed: '.$exception->getMessage(), 'completed_at' => now()]);
                $source->update(['processing_status' => 'failed']);
                $this->audit->record('standards-import.failed', $locked, [], $locked->fresh()->toArray());
            });
            throw $exception;
        }
        return $import->fresh('proposals');
    }

    public function bulkUpdate(CurriculumImport $import, array $submitted): CurriculumImport
    {
        return DB::transaction(function () use ($import, $submitted): CurriculumImport {
            $locked = CurriculumImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            $this->assertReviewImport($locked);
            $proposals = $locked->proposals()->lockForUpdate()->get()->keyBy('id');
            if (collect($submitted)->keys()->map(fn ($id) => (int) $id)->sort()->values()->all() !== $proposals->keys()->sort()->values()->all()) {
                throw ValidationException::withMessages(['proposals' => 'Submit every current standards proposal in one bulk save.']);
            }
            $normalized = [];
            foreach ($submitted as $id => $row) {
                $proposal = $proposals->get((int) $id);
                if (! $proposal || (int) ($row['id'] ?? 0) !== (int) $id) throw ValidationException::withMessages(["proposals.{$id}.id" => 'The proposal ID does not match its review row.']);
                $included = filter_var($row['included'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $sequence = (int) ($row['sequence'] ?? 0);
                if ($sequence < 1 || $sequence > 65535) throw ValidationException::withMessages(["proposals.{$id}.sequence" => 'Sequence must be between 1 and 65535.']);
                $code = $proposal->proposal_type === 'strand' ? null : trim((string) ($row['standard_code'] ?? ''));
                $statement = trim((string) ($row['statement'] ?? ''));
                if ($proposal->proposal_type !== 'strand' && $code === '') throw ValidationException::withMessages(["proposals.{$id}.standard_code" => 'Enter the printed standard code.']);
                if ($statement === '') throw ValidationException::withMessages(["proposals.{$id}.statement" => 'Enter the source wording.']);
                $normalizedCode = $proposal->proposal_type === 'strand' ? $proposal->normalized_code : $this->normalizeCode($code);
                if ($included && isset($normalized[$normalizedCode])) throw ValidationException::withMessages(["proposals.{$id}.standard_code" => 'Included standard codes must be unique.']);
                if ($included) $normalized[$normalizedCode] = true;
                if ($included && $proposal->parent_proposal_id && ! filter_var($submitted[$proposal->parent_proposal_id]['included'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    throw ValidationException::withMessages(["proposals.{$id}.included" => 'Include the parent strand or standard first.']);
                }
                $before = $proposal->toArray();
                $proposal->update([
                    'included' => $included, 'sequence' => $sequence, 'standard_code' => $code,
                    'normalized_code' => $normalizedCode, 'name' => $code ?: $proposal->name,
                    'statement' => $statement, 'manually_edited' => $this->isEdited($proposal, $code, $statement, $included, $sequence),
                ]);
                $this->audit->record('standards-proposal.updated', $proposal, $before, $proposal->fresh()->toArray());
            }
            $locked->increment('review_version');
            $this->audit->record('standards-import.review-saved', $locked, [], $locked->fresh()->toArray());
            return $locked->fresh('proposals');
        });
    }

    public function approve(CurriculumImport $import, int $reviewVersion): CurriculumImport
    {
        try {
            return DB::transaction(function () use ($import, $reviewVersion): CurriculumImport {
            $locked = CurriculumImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            $this->assertReviewImport($locked);
            if ($locked->review_version !== $reviewVersion) throw ValidationException::withMessages(['approval' => 'The saved review changed. Reload before approval.']);
            $source = $locked->source()->lockForUpdate()->firstOrFail();
            $file = AcademicSourceFile::query()->whereKey($locked->academic_source_file_id)->lockForUpdate()->firstOrFail();
            $source->loadMissing(['currentFile', 'links', 'gradeLevel']);
            $this->assertEligible($source, 'approval');
            [$subjectId, $frameworkId] = $this->context($source);
            if ($locked->academic_source_id !== $source->id || $file->academic_source_id !== $source->id
                || $source->currentFile?->id !== $file->id || $locked->subject_id !== $subjectId
                || $locked->grade_level_id !== $source->grade_level_id || $locked->school_year_id !== $source->school_year_id
                || $locked->standards_framework_id !== $frameworkId) {
                throw ValidationException::withMessages(['approval' => 'The standards import context no longer matches its current source.']);
            }
            $proposals = $locked->proposals()->lockForUpdate()->get()->keyBy('id');
            $included = $proposals->where('included', true);
            if ($included->where('proposal_type', 'standard')->isEmpty()) throw ValidationException::withMessages(['approval' => 'Include at least one parent standard.']);
            foreach ($included as $proposal) if ($proposal->parent_proposal_id && ! $included->has($proposal->parent_proposal_id)) {
                throw ValidationException::withMessages(["proposals.{$proposal->id}.included" => 'An included standard requires its included parent.']);
            }
            $labels = $this->metadataNormalizer->normalize($locked->document_metadata ?? [], $locked->adopted_label);
            $version = $labels['version_label'] ?? 'Unversioned';
            $this->validateMaterialization($locked, $included, $labels, $version);
            $codes = $included->pluck('normalized_code')->filter();
            if (Standard::query()->where('standards_framework_id', $frameworkId)->where('subject_id', $subjectId)
                ->where('grade_level_id', $locked->grade_level_id)->where('version_label', $version)->whereIn('normalized_code', $codes)->exists()) {
                throw ValidationException::withMessages(['approval' => 'This standards version already contains one or more included codes.']);
            }
            $records = [];
            foreach ($included->sortBy('sequence') as $proposal) {
                $parent = $proposal->parent_proposal_id ? ($records[$proposal->parent_proposal_id] ?? null) : null;
                if ($proposal->parent_proposal_id && ! $parent) throw ValidationException::withMessages(["proposals.{$proposal->id}.parent_proposal_id" => 'The parent standard could not be materialized.']);
                $record = Standard::create([
                    'standards_framework_id' => $frameworkId, 'subject_id' => $subjectId, 'grade_level_id' => $locked->grade_level_id,
                    'parent_standard_id' => $parent?->id, 'record_type' => $proposal->proposal_type,
                    'title' => $proposal->proposal_type === 'strand' ? $proposal->name : $proposal->standard_code,
                    'standard_code' => $proposal->standard_code, 'normalized_code' => $proposal->normalized_code,
                    'strand' => $proposal->strand, 'statement' => $proposal->statement, 'sequence' => $proposal->sequence,
                    'version_label' => $version, 'adopted_label' => $labels['adopted_label'],
                    'effective_label' => $labels['effective_label'], 'status' => 'active',
                    ...$this->provenance($locked, $proposal),
                ]);
                $records[$proposal->id] = $record;
                $this->audit->record('standard.imported', $record, [], $record->toArray());
            }
            $locked->update(['status' => 'approved', 'approved_by_user_id' => auth()->id(), 'approved_at' => now()]);
            $source->update(['processing_status' => 'completed']);
            $this->audit->record('standards-import.approved', $locked, [], $locked->fresh()->toArray());
            return $locked->fresh(['standards.children', 'proposals']);
            });
        } catch (QueryException $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'approval' => 'Approval could not be completed. No standards were imported; review the saved values and try again.',
            ]);
        }
    }

    private function context(AcademicSource $source): array
    {
        $subjectId = (int) ($source->links->firstWhere('link_type', 'subject')?->link_id ?? 0);
        $frameworkId = (int) ($source->links->firstWhere('link_type', 'standards_framework')?->link_id
            ?? AcademicYearConfiguration::query()->where('school_year_id', $source->school_year_id)->value('standards_framework_id'));
        if (! $subjectId) throw ValidationException::withMessages(['source' => 'Link this standards source to its subject.']);
        if (! $frameworkId || ! StandardsFramework::query()->whereKey($frameworkId)->exists()) throw ValidationException::withMessages(['source' => 'Select a visible standards framework for this school year.']);
        return [$subjectId, $frameworkId];
    }

    private function assertEligible(AcademicSource $source, string $key): void
    {
        if ($source->archived_at || $source->review_status !== 'reviewed' || ! in_array($source->source_category, ['curriculum', 'standards'], true)
            || $source->source_kind !== 'upload' || ! $source->currentFile || $source->currentFile->mime_type !== 'application/pdf'
            || ! $source->grade_level_id || ! $source->school_year_id) {
            throw ValidationException::withMessages([$key => 'Use a reviewed, non-archived PDF with validated subject, grade, and school-year context.']);
        }
    }

    private function assertReviewImport(CurriculumImport $import): void
    {
        if ($import->import_type !== 'standards' || $import->status !== 'review') throw ValidationException::withMessages(['review' => $import->status === 'approved' ? 'Approved standards imports are read-only.' : 'Only a standards import awaiting review can be changed.']);
    }

    private function storeProposals(CurriculumImport $import, array $rows): void
    {
        $parents = [];
        foreach ($rows as $row) {
            $values = $row->toArray();
            $values['parent_proposal_id'] = $row->parentKey ? ($parents[$row->parentKey] ?? null) : null;
            $proposal = $import->proposals()->create($values); $parents[$row->key] = $proposal->id;
        }
    }

    private function provenance(CurriculumImport $import, CurriculumImportProposal $proposal): array
    {
        return ['academic_source_id' => $import->academic_source_id, 'academic_source_file_id' => $import->academic_source_file_id,
            'curriculum_import_id' => $import->id, 'curriculum_import_proposal_id' => $proposal->id,
            'source_page' => $proposal->source_page, 'source_raw_text' => $proposal->raw_text,
            'parser_key' => $import->parser_key, 'parser_version' => $import->parser_version,
            'source_confidence' => $proposal->confidence, 'source_note' => $proposal->parser_note];
    }
    private function validateMaterialization(CurriculumImport $import, $proposals, array $labels, string $version): void
    {
        $errors = [];
        $this->validateLength($errors, 'approval', 'Document version label', $version, 100);
        $this->validateLength($errors, 'approval', 'Adopted label', $labels['adopted_label'] ?? null, 100);
        $this->validateLength($errors, 'approval', 'Effective label', $labels['effective_label'] ?? null, 100);
        $this->validateLength($errors, 'approval', 'Parser key', $import->parser_key, 80);
        $this->validateLength($errors, 'approval', 'Parser version', $import->parser_version, 50);
        foreach ($proposals as $proposal) {
            $key = "proposals.{$proposal->id}";
            if (! in_array($proposal->proposal_type, ['strand', 'standard', 'student_expectation'], true)) {
                $errors["{$key}.proposal_type"] = 'The record type cannot be imported as a standard.';
            }
            $title = $proposal->proposal_type === 'strand' ? $proposal->name : $proposal->standard_code;
            $this->validateLength($errors, "{$key}.name", 'Title', $title, 255);
            $this->validateLength($errors, "{$key}.standard_code", 'Standard code', $proposal->standard_code, 100);
            $this->validateLength($errors, "{$key}.normalized_code", 'Normalized standard code', $proposal->normalized_code, 100, true);
            $this->validateLength($errors, "{$key}.strand", 'Strand', $proposal->strand, 100);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages([
                'approval' => 'Approval could not be completed because one or more saved values exceed the standards record limits. Correct the highlighted values and save the review again.',
                ...$errors,
            ]);
        }
    }
    private function validateLength(array &$errors, string $key, string $label, ?string $value, int $maximum, bool $required = false): void
    {
        if ($required && ($value === null || $value === '')) $errors[$key] = "{$label} is required.";
        elseif ($value !== null && mb_strlen($value) > $maximum) $errors[$key] = "{$label} may not be greater than {$maximum} characters.";
    }
    private function normalizeCode(string $code): string { return strtoupper(preg_replace('/[^A-Z0-9.]/i', '', $code) ?? $code); }
    private function isEdited(CurriculumImportProposal $proposal, ?string $code, string $statement, bool $included, int $sequence): bool
    {
        $original = $proposal->original_values ?? [];
        return $code !== ($original['standard_code'] ?? null) || $statement !== ($original['statement'] ?? null)
            || $included !== (bool) ($original['included'] ?? true) || $sequence !== (int) ($original['sequence'] ?? 0);
    }
}
