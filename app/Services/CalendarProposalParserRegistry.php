<?php

namespace App\Services;

use App\Contracts\CalendarProposalParser;
use App\Models\AcademicSource;

final class CalendarProposalParserRegistry
{
    public function __construct(
        private CyFairDistrictCalendarParser $cyFair,
        private DistrictCalendarProposalParser $general,
    ) {}

    public function select(array $pages, AcademicSource $source): CalendarProposalParser
    {
        return $this->cyFair->supports($pages, $source) ? $this->cyFair : $this->general;
    }
}
