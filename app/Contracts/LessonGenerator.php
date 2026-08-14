<?php

namespace App\Contracts;

use App\Data\GeneratedLessonData;
use App\Data\LessonGenerationContext;

interface LessonGenerator
{
    public function key(): string;

    public function version(): string;

    /** @return list<GeneratedLessonData> */
    public function generate(LessonGenerationContext $context): array;
}
