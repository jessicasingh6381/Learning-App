<?php

namespace App\Services;

use App\Domain\Calendars\CalendarProfileCompatibility;
use App\Domain\Calendars\ScheduledInstructionalDayCalculator;
use App\Models\StudentEnrollment;
use Carbon\CarbonImmutable;

final class InstructionalDateService
{
    public function __construct(private ScheduledInstructionalDayCalculator $calculator, private CalendarProfileCompatibility $compatibility) {}

    public function today(StudentEnrollment $enrollment): string
    {
        $enrollment->loadMissing('schoolYear');
        return CarbonImmutable::now($enrollment->schoolYear->timezone)->format('Y-m-d');
    }

    public function isInstructional(StudentEnrollment $enrollment, string $date): bool
    {
        $enrollment->loadMissing('schoolYear.academicConfiguration.calendarProfile.events');
        $year=$enrollment->schoolYear;$configuration=$year->academicConfiguration;$calendar=$configuration?->calendarProfile;
        if(!$calendar || !$this->compatibility->supports($calendar,$year,$configuration->education_provider_id)) return false;
        if($date<$year->start_date->format('Y-m-d') || $date>$year->end_date->format('Y-m-d')) return false;
        return $this->calculator->instructionalDates($date,$date,$year->instructional_weekdays,$calendar->events)===[$date];
    }
}
