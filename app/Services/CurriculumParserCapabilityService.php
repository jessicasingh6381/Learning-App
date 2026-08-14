<?php

namespace App\Services;

use App\Data\CurriculumCapabilityAssessment;
use App\Data\CurriculumParserCapability as CapabilityData;
use App\Models\AcademicSource;
use App\Models\CurriculumParserCapability as CapabilityModel;
use App\Models\CurriculumFormatProfile;
use App\Models\GradeLevel;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CurriculumParserCapabilityService
{
    public function __construct(
        private CurriculumParserRegistry $registry,
        private CurriculumSourcePdfExtractor $extractor,
        private CurriculumDocumentStructureDetector $detector,
        private AuditService $audit,
    ) {}

    public function cached(AcademicSource $source): CapabilityData
    {
        $source->loadMissing('currentFile');
        $file = $source->currentFile;
        if (! $file) return $this->unknown(null, null);
        $signature = $this->registry->signature();
        $query = CapabilityModel::query()
            ->where('academic_source_id', $source->id)
            ->where('academic_source_file_id', $file->id)
            ->where('file_checksum', $file->checksum_sha256)
            ->where('registry_signature', $signature);
        $model = $source->relationLoaded('curriculumParserCapabilities')
            ? $source->curriculumParserCapabilities->first(fn ($item) => $item->academic_source_file_id === $file->id
                && hash_equals($item->file_checksum, $file->checksum_sha256)
                && hash_equals($item->registry_signature, $signature))
            : $query->latest('assessed_at')->first();

        return $model ? $this->fromModel($model) : $this->unknown($file->id, $file->checksum_sha256);
    }

    public function assess(AcademicSource $source, bool $force = false): CapabilityData
    {
        $this->validateSource($source);
        if (! $force && ($cached = $this->cached($source))->state !== 'unknown') return $cached;

        return $this->assessWithPages($source)->capability;
    }

    public function assessForImport(AcademicSource $source): CurriculumCapabilityAssessment
    {
        return $this->assessWithPages($source);
    }

    public function assertCurrentSupported(AcademicSource $source, CapabilityData $capability): void
    {
        $source->loadMissing(['currentFile', 'educationProvider', 'gradeLevel', 'links']);
        $file = $source->currentFile;
        if (! $file || $capability->sourceFileId !== $file->id
            || ! $capability->fileChecksum || ! hash_equals($file->checksum_sha256, $capability->fileChecksum)
            || ! hash_equals($this->registry->signature(), $capability->registrySignature)) {
            throw ValidationException::withMessages(['source' => 'Outline support must be checked again for the current PDF.']);
        }
        if (! $capability->supported() || ! $capability->parserKey || ! $capability->parserVersion) {
            throw ValidationException::withMessages(['source' => $capability->userMessage]);
        }
        $parser = $this->registry->parser($capability->parserKey, $capability->parserVersion);
        if (! $parser || ! in_array($parser, $this->registry->applicable($source, $file), true)) {
            throw ValidationException::withMessages(['source' => 'Outline support must be checked again for the current source settings.']);
        }
    }

    public function parser(CapabilityData $capability): \App\Contracts\CurriculumOutlineParser
    {
        return $this->registry->parser((string) $capability->parserKey, (string) $capability->parserVersion)
            ?? throw ValidationException::withMessages(['source' => 'Outline support must be checked again before extraction.']);
    }

    private function assessWithPages(AcademicSource $source): CurriculumCapabilityAssessment
    {
        $this->validateSource($source);
        $file = $source->currentFile;
        if ($this->registry->applicable($source, $file) === []) {
            $result = $this->registry->assess([], $source, $file);
            return new CurriculumCapabilityAssessment($this->persist($source, $result), []);
        }

        try {
            $pages = $this->extractor->extract($file);
            if ($pages === [] || collect($pages)->every(fn (array $page) => trim((string) ($page['text'] ?? '')) === '')) {
                $result = new CapabilityData(
                    'failed', null, null, null, null,
                    'We could not read the text in this PDF. It may be scanned or image-based.',
                    'PDF extraction returned no usable text; OCR is not enabled.', [],
                    $file->id, $file->checksum_sha256, $this->registry->signature(), now(),
                );
            } else {
                $result = $this->registry->assess($pages, $source, $file);
            }
        } catch (Throwable $exception) {
            report($exception);
            $pages = [];
            $result = new CapabilityData(
                'failed', null, null, null, null,
                'We could not read the text in this PDF. It may be scanned or image-based.',
                'PDF extraction failed: '.$exception->getMessage(), [],
                $file->id, $file->checksum_sha256, $this->registry->signature(), now(),
            );
        }

        $capability = $this->persist($source, $result);
        if ($capability->supported() && $capability->parserKey === StructuredCustomCurriculumParser::KEY) {
            $this->supersedeDraftFormatProfile($source, $pages, $capability);
        }

        return new CurriculumCapabilityAssessment($capability, $pages);
    }

    private function validateSource(AcademicSource $source): void
    {
        $source->loadMissing(['currentFile', 'schoolYear', 'gradeLevel', 'links', 'educationProvider']);
        if ($source->archived_at || $source->review_status !== 'reviewed'
            || ! in_array($source->source_category, ['curriculum', 'pacing', 'scope_and_sequence'], true)) {
            throw ValidationException::withMessages(['source' => 'Review this active curriculum source before checking outline support.']);
        }
        if ($source->source_kind !== 'upload' || ! $source->currentFile
            || $source->currentFile->mime_type !== 'application/pdf' || $source->currentFile->extension !== 'pdf') {
            throw ValidationException::withMessages(['source' => 'A validated current PDF is required to check outline support.']);
        }
        $subjectIds = $source->links->where('link_type', 'subject')->pluck('link_id')->unique();
        if (! $source->schoolYear || ! GradeLevel::query()->whereKey($source->grade_level_id)->where('is_active', true)->exists()
            || $subjectIds->count() !== 1 || ! Subject::query()->whereKey($subjectIds->first())->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['source' => 'Complete the school year, grade, and subject settings before checking outline support.']);
        }
    }

    private function persist(AcademicSource $source, CapabilityData $result): CapabilityData
    {
        $model = CapabilityModel::query()->updateOrCreate([
            'academic_source_file_id' => $result->sourceFileId,
            'registry_signature' => $result->registrySignature,
        ], [
            'academic_source_id' => $source->id,
            'file_checksum' => $result->fileChecksum,
            'state' => $result->state,
            'parser_key' => $result->parserKey,
            'parser_version' => $result->parserVersion,
            'extraction_method' => $result->extractionMethod,
            'recognition_score' => $result->recognitionScore,
            'document_family' => $result->documentFamily,
            'user_message' => $result->userMessage,
            'internal_diagnostic' => $result->internalDiagnostic,
            'candidate_parsers' => $result->candidateParsers,
            'assessed_at' => now(),
        ]);

        return $this->fromModel($model);
    }

    private function supersedeDraftFormatProfile(AcademicSource $source, array $pages, CapabilityData $capability): void
    {
        $detected = $this->detector->detect($pages, $source);
        DB::transaction(function () use ($source, $capability, $detected): void {
            $profile = CurriculumFormatProfile::query()
                ->where('example_academic_source_file_id', $source->currentFile->id)
                ->where('status', 'draft')->lockForUpdate()->first();
            if (! $profile) return;
            $before = $profile->toArray();
            $profile->update([
                'status' => 'superseded',
                'document_family' => StructuredCustomCurriculumParser::FAMILY,
                'detected_structure' => $detected,
                'mapping_rules' => [
                    'strategy' => 'automatic_structured_parser',
                    'confirmed_period_headings' => [],
                    'confirmed_unit_rows' => $detected['unit_rows'] ?? [],
                    'confirmed_assessment_rows' => [],
                    'superseded_by_parser' => $capability->parserKey,
                    'superseded_by_version' => $capability->parserVersion,
                ],
            ]);
            $this->audit->record('curriculum-format-profile.superseded-by-parser', $profile, $before, $profile->fresh()->toArray());
        });
    }

    private function fromModel(CapabilityModel $model): CapabilityData
    {
        return new CapabilityData(
            $model->state, $model->parser_key, $model->parser_version, $model->extraction_method,
            $model->recognition_score, $model->user_message, $model->internal_diagnostic,
            $model->candidate_parsers ?? [], $model->academic_source_file_id, $model->file_checksum,
            $model->registry_signature, $model->assessed_at, $model->document_family,
        );
    }

    private function unknown(?int $fileId, ?string $checksum): CapabilityData
    {
        return new CapabilityData(
            'unknown', null, null, null, null,
            'Outline support has not been checked for this PDF.', null, [],
            $fileId, $checksum, $this->registry->signature(), null,
        );
    }
}
