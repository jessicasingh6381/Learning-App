<?php

namespace App\Contracts;

use App\Models\AcademicSource;
use App\Models\SchoolYear;

interface CalendarProposalParser
{
    public function supports(array $pages, AcademicSource $source): bool;

    /** @return array<int, array<string, mixed>> */
    public function parse(array $pages, SchoolYear $year): array;

    public function version(): string;

    public function extractionMethod(): string;
}
