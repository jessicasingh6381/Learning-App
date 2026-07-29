<?php

namespace Tests\Unit;

use App\Domain\SchoolYears\BaseInstructionalDayCalculator;
use App\Domain\SchoolYears\InstructionalSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BaseInstructionalDayCalculatorTest extends TestCase
{
    /**
     * @param  array<int, int>  $weekdays
     */
    #[DataProvider('dateRangeProvider')]
    public function test_it_calculates_inclusive_base_instructional_days(
        string $startDate,
        string $endDate,
        array $weekdays,
        int $expected,
    ): void {
        $this->assertSame(
            $expected,
            (new BaseInstructionalDayCalculator)->calculate(
                $startDate,
                $endDate,
                $weekdays,
            ),
        );
    }

    /**
     * @return array<string, array{string, string, array<int, int>, int}>
     */
    public static function dateRangeProvider(): array
    {
        return [
            'five-day full school-year range' => [
                '2026-08-12',
                '2027-05-27',
                [1, 2, 3, 4, 5],
                207,
            ],
            'four-day Monday through Thursday' => [
                '2026-08-12',
                '2027-05-27',
                [1, 2, 3, 4],
                166,
            ],
            'custom Tuesday through Friday' => [
                '2026-08-12',
                '2027-05-27',
                [2, 3, 4, 5],
                166,
            ],
            'non-contiguous Monday Wednesday Friday' => [
                '2026-08-12',
                '2027-05-27',
                [1, 3, 5],
                124,
            ],
            'instructional start date included' => [
                '2026-08-10',
                '2026-08-11',
                [1],
                1,
            ],
            'non-instructional start date excluded' => [
                '2026-08-10',
                '2026-08-11',
                [2],
                1,
            ],
            'instructional end date included' => [
                '2026-08-10',
                '2026-08-11',
                [2],
                1,
            ],
            'non-instructional end date excluded' => [
                '2026-08-10',
                '2026-08-11',
                [1],
                1,
            ],
            'partial week' => [
                '2026-08-12',
                '2026-08-14',
                [1, 2, 3, 4, 5],
                3,
            ],
            'cross-month range' => [
                '2026-01-30',
                '2026-02-02',
                [1, 2, 3, 4, 5],
                2,
            ],
            'cross-year range' => [
                '2026-12-31',
                '2027-01-04',
                [1, 2, 3, 4, 5],
                3,
            ],
            'leap-year range includes February 29' => [
                '2024-02-28',
                '2024-03-01',
                [1, 2, 3, 4, 5],
                3,
            ],
        ];
    }

    #[DataProvider('weekdayLabelProvider')]
    public function test_it_formats_weekday_labels(array $weekdays, string $expected): void
    {
        $this->assertSame($expected, InstructionalSchedule::label($weekdays));
    }

    /**
     * @return array<string, array{array<int, int>, string}>
     */
    public static function weekdayLabelProvider(): array
    {
        return [
            'five day' => [[1, 2, 3, 4, 5], 'Mon–Fri'],
            'four day' => [[1, 2, 3, 4], 'Mon–Thu'],
            'custom contiguous' => [[2, 3, 4, 5], 'Tue–Fri'],
            'custom non-contiguous' => [[1, 3, 5], 'Mon, Wed, Fri'],
        ];
    }
}
