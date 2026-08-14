<?php

namespace App\Services;

use App\Domain\Calendars\CalendarProfileCompatibility;
use App\Domain\Calendars\ScheduledInstructionalDayCalculator;
use App\Models\CurriculumImport;
use App\Models\CurriculumImportProposal;

final class CurriculumImportSchedulingService
{
    public const SOURCE = 'source';
    public const CALENDAR_CALCULATED = 'calendar_calculated';
    public const MANUAL_OVERRIDE = 'manual_override';

    public function __construct(
        private ScheduledInstructionalDayCalculator $calendar,
        private CalendarProfileCompatibility $compatibility,
        private AuditService $audit,
    ) {}

    /** Schedule units with a supported source duration and no source schedule. */
    public function schedule(CurriculumImport $import): int
    {
        $import->loadMissing([
            'schoolYear.academicConfiguration.calendarProfile.events',
            'packageCourse.curriculumPackage',
        ]);
        $year = $import->schoolYear;
        $configuration = $year?->academicConfiguration;
        $profile = $configuration?->calendarProfile;
        if (! $year || ! $profile || $profile->status !== 'active'
            || ! $this->compatibility->supports($profile, $year, $import->packageCourse?->curriculumPackage?->education_provider_id)) {
            return 0;
        }

        $weekdays = array_values(array_unique(array_map('intval', $year->instructional_weekdays ?? [])));
        if ($weekdays === []) return 0;
        $instructionalDates = $this->calendar->instructionalDates(
            $year->start_date->format('Y-m-d'),
            $year->end_date->format('Y-m-d'),
            $weekdays,
            $profile->events,
        );
        if ($instructionalDates === []) return 0;

        $dateIndexes = array_flip($instructionalDates);
        $cursor = 0;
        $scheduled = 0;
        $units = $import->proposals()->where('proposal_type', 'unit')->where('included', true)
            ->orderBy('sequence')->orderBy('id')->get();

        foreach ($units as $unit) {
            $metadata = $unit->parser_metadata ?? [];
            $hasScheduleValue = $unit->planned_start_date || $unit->planned_end_date || $unit->estimated_days;
            if ($hasScheduleValue) {
                $metadata['schedule_origin'] ??= self::SOURCE;
                if ($unit->planned_end_date && isset($dateIndexes[$unit->planned_end_date->format('Y-m-d')])) {
                    $cursor = $dateIndexes[$unit->planned_end_date->format('Y-m-d')] + 1;
                } else {
                    $cursor = null;
                }
                if (($unit->parser_metadata ?? []) !== $metadata) $unit->update(['parser_metadata' => $metadata]);
                continue;
            }

            $instructionalDays = $this->instructionalDays($metadata['duration_text'] ?? null, count($weekdays));
            if ($instructionalDays === null || $cursor === null) {
                $cursor = null;
                continue;
            }
            $lastIndex = $cursor + $instructionalDays - 1;
            if (! isset($instructionalDates[$cursor], $instructionalDates[$lastIndex])) break;

            $before = $unit->toArray();
            $metadata = [
                ...$metadata,
                'duration_origin' => $metadata['duration_origin'] ?? self::SOURCE,
                'schedule_origin' => self::CALENDAR_CALCULATED,
                'schedule_calendar_profile_id' => $profile->id,
                'schedule_calendar_name' => $profile->name,
                'schedule_school_year_id' => $year->id,
                'schedule_instructional_days' => $instructionalDays,
                'schedule_weekdays_per_week' => count($weekdays),
            ];
            $unit->update([
                'planned_start_date' => $instructionalDates[$cursor],
                'planned_end_date' => $instructionalDates[$lastIndex],
                'estimated_days' => $instructionalDays,
                'parser_metadata' => $metadata,
            ]);
            $this->audit->record('curriculum-import.proposal-calendar-scheduled', $unit, $before, $unit->fresh()->toArray());
            $cursor = $lastIndex + 1;
            $scheduled++;
        }

        return $scheduled;
    }

    public function instructionalDays(?string $duration, int $instructionalWeekdays): ?int
    {
        if (! $duration || $instructionalWeekdays < 1) return null;
        if (! preg_match('/^\s*(\d+)\s*(weeks?|sessions?|instructional\s+days?|days?)\s*$/iu', $duration, $match)) return null;
        $quantity = (int) $match[1];
        if ($quantity < 1) return null;

        return str_starts_with(mb_strtolower($match[2]), 'week')
            ? $quantity * $instructionalWeekdays
            : $quantity;
    }

    public function manualScheduleMetadata(CurriculumImportProposal $proposal, array $values): array
    {
        $metadata = $proposal->parser_metadata ?? [];
        $origin = $metadata['schedule_origin'] ?? null;
        if (! in_array($origin, [self::SOURCE, self::CALENDAR_CALCULATED], true)) return $metadata;

        $current = [
            'planned_start_date' => $proposal->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $proposal->planned_end_date?->format('Y-m-d'),
            'estimated_days' => $proposal->estimated_days,
        ];
        $submitted = [
            'planned_start_date' => ($values['planned_start_date'] ?? null) ?: null,
            'planned_end_date' => ($values['planned_end_date'] ?? null) ?: null,
            'estimated_days' => ($values['estimated_days'] ?? null) === null || $values['estimated_days'] === '' ? null : (int) $values['estimated_days'],
        ];
        if ($current === $submitted) return $metadata;

        return [
            ...$metadata,
            'schedule_origin' => self::MANUAL_OVERRIDE,
            'schedule_previous_origin' => $origin,
            'schedule_previous_values' => $current,
        ];
    }
}
