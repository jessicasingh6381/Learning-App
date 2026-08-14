<?php

namespace Tests\Unit;

use App\Domain\Calendars\ScheduledInstructionalDayCalculator;
use App\Domain\SchoolYears\BaseInstructionalDayCalculator;
use App\Models\CalendarEvent;
use PHPUnit\Framework\TestCase;

class ScheduledInstructionalDayCalculatorTest extends TestCase
{
    public function test_events_are_inclusive_deduplicated_clipped_and_use_documented_precedence(): void
    {
        $events = collect([
            $this->event('2026-08-12', '2026-08-14', 'non_instructional'),
            $this->event('2026-08-13', '2026-08-13', 'non_instructional'),
            $this->event('2026-08-15', null, 'instructional'),
            $this->event('2026-08-15', null, 'instructional'),
            $this->event('2026-08-14', null, 'instructional'),
            $this->event('2026-08-16', null, 'informational'),
            $this->event('2026-07-01', '2026-07-31', 'non_instructional'),
            $this->event('2027-06-01', null, 'instructional'),
        ]);

        $summary = $this->calculator()->summarize(
            '2026-08-12',
            '2026-08-16',
            [1, 2, 3, 4, 5],
            $events,
        );

        $this->assertSame([
            'base_days' => 3,
            'removed_days' => 2,
            'added_days' => 1,
            'scheduled_days' => 2,
        ], $summary);
    }

    public function test_date_only_calculation_is_stable_across_process_timezones(): void
    {
        $original = date_default_timezone_get();

        try {
            foreach (['Pacific/Kiritimati', 'America/Adak', 'UTC'] as $timezone) {
                date_default_timezone_set($timezone);
                $summary = $this->calculator()->summarize(
                    '2026-08-12',
                    '2027-05-27',
                    [1, 2, 3, 4, 5],
                    collect([$this->event('2026-08-12', null, 'non_instructional')]),
                );
                $this->assertSame(207, $summary['base_days']);
                $this->assertSame(206, $summary['scheduled_days']);
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_instructional_dates_skip_weekends_and_closures_but_include_overrides(): void
    {
        $dates = $this->calculator()->instructionalDates(
            '2026-08-12',
            '2026-08-17',
            [1, 2, 3, 4, 5],
            collect([
                $this->event('2026-08-13', null, 'non_instructional'),
                $this->event('2026-08-15', null, 'instructional'),
            ]),
        );

        $this->assertSame(['2026-08-12', '2026-08-14', '2026-08-15', '2026-08-17'], $dates);
    }

    private function calculator(): ScheduledInstructionalDayCalculator
    {
        return new ScheduledInstructionalDayCalculator(new BaseInstructionalDayCalculator);
    }

    private function event(string $start, ?string $end, string $effect): CalendarEvent
    {
        return new CalendarEvent([
            'event_date' => $start,
            'end_date' => $end,
            'event_type' => 'other',
            'name' => 'Test event',
            'instructional_effect' => $effect,
            'status' => 'active',
        ]);
    }
}
