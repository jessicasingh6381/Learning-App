<?php

namespace App\Domain\Calendars;

use App\Domain\SchoolYears\BaseInstructionalDayCalculator;
use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Enumerable;

final class ScheduledInstructionalDayCalculator
{
    public function __construct(private BaseInstructionalDayCalculator $baseCalculator) {}

    /**
     * Instructional overrides win over non-instructional events, which win over
     * the normal weekly schedule. A calendar date is therefore counted at most once.
     *
     * @param  array<int, int>  $instructionalWeekdays
     * @param  Enumerable<int, CalendarEvent>  $events
     * @return array{base_days: int, removed_days: int, added_days: int, scheduled_days: int}
     */
    public function summarize(
        string $startDate,
        string $endDate,
        array $instructionalWeekdays,
        Enumerable $events,
    ): array {
        $start = $this->dateOnly($startDate);
        $end = $this->dateOnly($endDate);
        $base = $this->baseCalculator->calculate($startDate, $endDate, $instructionalWeekdays);
        $baseWeekdays = array_fill_keys($instructionalWeekdays, true);
        $effects = [];

        foreach ($events as $event) {
            if ($event->status !== 'active' || $event->instructional_effect === 'informational') {
                continue;
            }

            $eventStart = $this->dateOnly($event->event_date->format('Y-m-d'));
            $eventEnd = $this->dateOnly(($event->end_date ?? $event->event_date)->format('Y-m-d'));
            $rangeStart = $eventStart->greaterThan($start) ? $eventStart : $start;
            $rangeEnd = $eventEnd->lessThan($end) ? $eventEnd : $end;

            if ($rangeEnd->lessThan($rangeStart)) {
                continue;
            }

            for ($date = $rangeStart; $date->lte($rangeEnd); $date = $date->addDay()) {
                $key = $date->format('Y-m-d');
                $current = $effects[$key] ?? null;

                if ($event->instructional_effect === 'instructional' || $current === null) {
                    $effects[$key] = $event->instructional_effect;
                }
            }
        }

        $removed = 0;
        $added = 0;

        foreach ($effects as $dateValue => $effect) {
            $isBaseDay = isset($baseWeekdays[$this->dateOnly($dateValue)->isoWeekday()]);

            if ($effect === 'non_instructional' && $isBaseDay) {
                $removed++;
            } elseif ($effect === 'instructional' && ! $isBaseDay) {
                $added++;
            }
        }

        return [
            'base_days' => $base,
            'removed_days' => $removed,
            'added_days' => $added,
            'scheduled_days' => $base - $removed + $added,
        ];
    }

    private function dateOnly(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $value);
    }
}
