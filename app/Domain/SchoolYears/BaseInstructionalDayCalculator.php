<?php

namespace App\Domain\SchoolYears;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class BaseInstructionalDayCalculator
{
    /**
     * @param  array<int, int>  $instructionalWeekdays
     */
    public function calculate(string $startDate, string $endDate, array $instructionalWeekdays): int
    {
        $start = $this->dateOnly($startDate);
        $end = $this->dateOnly($endDate);

        if ($end->lt($start)) {
            throw new InvalidArgumentException('The end date must not precede the start date.');
        }

        $selectedWeekdays = array_fill_keys($instructionalWeekdays, true);
        $count = 0;

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            if (isset($selectedWeekdays[$date->isoWeekday()])) {
                $count++;
            }
        }

        return $count;
    }

    private function dateOnly(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Invalid date-only value: {$value}");
        }

        return $date;
    }
}
