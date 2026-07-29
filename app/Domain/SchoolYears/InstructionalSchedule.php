<?php

namespace App\Domain\SchoolYears;

final class InstructionalSchedule
{
    public const TYPES = ['five_day', 'four_day', 'custom'];

    public const PRESET_WEEKDAYS = [
        'five_day' => [1, 2, 3, 4, 5],
        'four_day' => [1, 2, 3, 4],
    ];

    private const WEEKDAY_LABELS = [
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
        7 => 'Sun',
    ];

    /**
     * @param  array<int, int>  $weekdays
     * @return array<int, int>
     */
    public static function normalize(array $weekdays): array
    {
        sort($weekdays, SORT_NUMERIC);

        return array_values($weekdays);
    }

    /**
     * @param  array<int, int>  $weekdays
     */
    public static function matchesPreset(string $type, array $weekdays): bool
    {
        $preset = self::PRESET_WEEKDAYS[$type] ?? null;

        return $preset === null || self::normalize($weekdays) === $preset;
    }

    /**
     * @param  array<int, int>  $weekdays
     */
    public static function label(array $weekdays): string
    {
        $weekdays = self::normalize($weekdays);
        $labels = array_map(
            static fn (int $weekday): string => self::WEEKDAY_LABELS[$weekday],
            $weekdays,
        );

        if (count($weekdays) > 1 && self::isContiguous($weekdays)) {
            return $labels[0].'–'.$labels[array_key_last($labels)];
        }

        return implode(', ', $labels);
    }

    /**
     * @param  array<int, int>  $weekdays
     */
    private static function isContiguous(array $weekdays): bool
    {
        foreach (array_slice($weekdays, 1) as $index => $weekday) {
            if ($weekday !== $weekdays[$index] + 1) {
                return false;
            }
        }

        return true;
    }
}
