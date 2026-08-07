<?php

namespace App\Data;

final readonly class CurriculumParserApplicability
{
    public function __construct(
        public array $providerCodes,
        public array $subjectCodes,
        public array $gradeCodes,
        public array $sourceCategories,
        public array $mimeTypes,
        public array $extensions,
        public string $documentFamily,
        public int $priority = 0,
    ) {}
}
