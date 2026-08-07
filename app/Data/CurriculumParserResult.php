<?php

namespace App\Data;

final readonly class CurriculumParserResult
{
    /** @param array<int, CurriculumProposalData> $proposals */
    public function __construct(
        public string $title,
        public ?string $revisionDate,
        public ?string $gradeLabel,
        public ?string $subjectLabel,
        public ?string $schoolYearLabel,
        public array $proposals,
        public ?string $diagnostic = null,
        public array $metadata = [],
    ) {}
}
