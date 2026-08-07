<?php

namespace App\Data;

final readonly class CurriculumProposalData
{
    public function __construct(
        public string $key,
        public ?string $parentKey,
        public string $proposalType,
        public int $sequence,
        public string $name,
        public ?string $plannedStartDate = null,
        public ?string $plannedEndDate = null,
        public ?int $estimatedDays = null,
        public ?string $unitType = null,
        public ?string $reportingPeriod = null,
        public array $standardCodes = [],
        public int $sourcePage = 1,
        public ?string $rawText = null,
        public ?string $parserNote = null,
        public float $confidence = 1.0,
        public array $parserMetadata = [],
        public ?string $componentType = null,
        public ?string $description = null,
        public ?string $summary = null,
        public ?string $strand = null,
        public ?string $standardCode = null,
        public ?string $normalizedCode = null,
        public ?string $statement = null,
    ) {}

    public function toArray(): array
    {
        $editable = [
            'proposal_type' => $this->proposalType, 'included' => true, 'sequence' => $this->sequence,
            'name' => $this->name, 'description' => $this->description, 'summary' => $this->summary, 'planned_start_date' => $this->plannedStartDate,
            'planned_end_date' => $this->plannedEndDate, 'estimated_days' => $this->estimatedDays,
            'unit_type' => $this->unitType, 'component_type' => $this->componentType, 'reporting_period' => $this->reportingPeriod,
            'standard_codes' => $this->standardCodes,
            'strand' => $this->strand, 'standard_code' => $this->standardCode,
            'normalized_code' => $this->normalizedCode, 'statement' => $this->statement,
        ];

        return [
            ...$editable, 'source_page' => $this->sourcePage, 'raw_text' => $this->rawText,
            'parser_note' => $this->parserNote, 'confidence' => $this->confidence,
            'manually_edited' => false, 'original_values' => $editable,
            'parser_metadata' => ['proposal_key' => $this->key, ...$this->parserMetadata],
        ];
    }
}
