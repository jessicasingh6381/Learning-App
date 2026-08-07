<?php

namespace App\Data;

use Carbon\CarbonInterface;

final readonly class CurriculumParserCapability
{
    public function __construct(
        public string $state,
        public ?string $parserKey,
        public ?string $parserVersion,
        public ?string $extractionMethod,
        public ?float $recognitionScore,
        public string $userMessage,
        public ?string $internalDiagnostic,
        public array $candidateParsers,
        public ?int $sourceFileId,
        public ?string $fileChecksum,
        public string $registrySignature,
        public ?CarbonInterface $assessedAt,
        public ?string $documentFamily = null,
    ) {}

    public function supported(): bool
    {
        return $this->state === 'supported';
    }

    public function toArray(bool $includeInternal = false): array
    {
        return [
            'state' => $this->state,
            'parser_key' => $this->parserKey,
            'parser_version' => $this->parserVersion,
            'extraction_method' => $this->extractionMethod,
            'recognition_score' => $this->recognitionScore,
            'message' => $this->userMessage,
            'candidate_parsers' => $includeInternal ? $this->candidateParsers : [],
            'file_checksum' => $includeInternal ? $this->fileChecksum : null,
            'registry_signature' => $includeInternal ? $this->registrySignature : null,
            'internal_diagnostic' => $includeInternal ? $this->internalDiagnostic : null,
            'assessed_at' => $this->assessedAt?->toIso8601String(),
            'document_family' => $this->documentFamily,
        ];
    }
}
