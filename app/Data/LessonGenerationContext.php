<?php

namespace App\Data;

final readonly class LessonGenerationContext
{
    public function __construct(
        public array $tenant,
        public array $student,
        public array $enrollment,
        public array $schoolYear,
        public array $grade,
        public array $subject,
        public array $course,
        public array $curriculum,
        public array $unit,
        public array $components,
        public array $objectives,
        public array $skills,
        public array $assessments,
        public array $projectMilestones,
        public array $standardAlignments,
        public string $lessonMode = 'full',
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
