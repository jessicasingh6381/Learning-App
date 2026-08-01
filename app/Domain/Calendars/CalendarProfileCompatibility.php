<?php

namespace App\Domain\Calendars;

use App\Models\CalendarProfile;
use App\Models\SchoolYear;

final class CalendarProfileCompatibility
{
    public function supports(CalendarProfile $calendar, SchoolYear $schoolYear, ?int $educationProviderId = null): bool
    {
        return in_array($calendar->status, ['draft', 'active'], true)
            && $calendar->start_date->format('Y-m-d') <= $schoolYear->start_date->format('Y-m-d')
            && $calendar->end_date->format('Y-m-d') >= $schoolYear->end_date->format('Y-m-d')
            && ($educationProviderId === null
                || $calendar->education_provider_id === null
                || $calendar->education_provider_id === $educationProviderId);
    }
}
