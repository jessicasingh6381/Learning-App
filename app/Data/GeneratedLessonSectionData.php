<?php

namespace App\Data;

final readonly class GeneratedLessonSectionData
{
    public function __construct(
        public string $sectionType,
        public string $content,
        public string $audience = 'shared',
        public ?string $title = null,
        public ?int $estimatedMinutes = null,
        public array $metadata = [],
    ) {}
}
