<?php

namespace App\Data;

final readonly class GeneratedLessonData
{
    /**
     * @param list<GeneratedLessonSectionData> $sections
     * @param list<GeneratedLessonResourceData> $resources
     * @param list<int> $curriculumComponentIds
     * @param list<int> $curriculumStandardAlignmentIds
     */
    public function __construct(
        public int $sequence,
        public string $title,
        public ?string $learningObjective = null,
        public ?string $completionCriteria = null,
        public ?int $estimatedMinutes = null,
        public int $estimatedPreparationMinutes = 0,
        public int $suggestedSessions = 1,
        public string $lessonMode = 'full',
        public array $sections = [],
        public array $resources = [],
        public array $curriculumComponentIds = [],
        public array $curriculumStandardAlignmentIds = [],
        public array $metadata = [],
    ) {}
}
