<?php

namespace App\Data;

final readonly class CurriculumCapabilityAssessment
{
    public function __construct(
        public CurriculumParserCapability $capability,
        public array $pages,
    ) {}
}
