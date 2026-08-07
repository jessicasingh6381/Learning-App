<?php

namespace App\Services;

use App\Contracts\CalendarProposalParser;
use App\Models\AcademicSource;
use App\Models\SchoolYear;
use Carbon\CarbonImmutable;

final class DistrictCalendarProposalParser implements CalendarProposalParser
{
    public const VERSION = 'general-text-v2';

    public function supports(array $pages, AcademicSource $source): bool
    {
        return true;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function extractionMethod(): string
    {
        return 'pdf_text';
    }

    /** @param array<int, array{page: int, text: string}> $pages
     *  @return array<int, array<string, mixed>>
     */
    public function parse(array $pages, SchoolYear $year): array
    {
        $results = [];
        $abbreviations = $this->abbreviations($pages);
        foreach ($pages as $page) {
            foreach (preg_split('/\R+/u', $page['text']) ?: [] as $rawLine) {
                $line = trim(preg_replace('/\s+/u', ' ', $rawLine) ?? '');
                if ($this->isAbbreviationDefinition($line, $abbreviations)) {
                    continue;
                }
                $expandedLine = $this->expandAbbreviations($line, $abbreviations);
                if ($line === '' || ! $this->looksRelevant($expandedLine)) {
                    continue;
                }

                [$start, $end, $dateText, $confidence] = $this->dates($expandedLine, $year);
                [$type, $effect] = $this->classify($expandedLine);
                $name = $dateText === '' ? $expandedLine : trim(str_replace($dateText, ' ', $expandedLine), " \t\n\r\0\x0B-:–—");
                $name = $name !== '' ? $name : $this->label($type);

                $results[] = [
                    'event_date' => $start, 'end_date' => $end, 'name' => mb_substr($name, 0, 255),
                    'event_type' => $type, 'instructional_effect' => $effect,
                    'confidence' => $start ? $confidence : 0.35, 'source_page' => $page['page'],
                    'raw_text' => mb_substr($line, 0, 5000),
                    'parser_note' => $start
                        ? ($expandedLine !== $line ? 'Expanded an abbreviation defined in the PDF legend.' : null)
                        : 'A relevant label was found, but its date was ambiguous or missing.',
                    'included' => $start !== null,
                ];
            }
        }

        return collect($results)->unique(fn ($item) => implode('|', [
            $item['event_date'], $item['end_date'], mb_strtolower($item['name']), $item['event_type'],
        ]))->values()->all();
    }

    private function looksRelevant(string $line): bool
    {
        return preg_match('/first day|last day|holiday|no school|closed|closure|break|workday|work day|professional development|staff development|early release|make.?up|bad weather|inclement weather/i', $line) === 1;
    }

    /** @return array{?string, ?string, string, float} */
    private function dates(string $line, SchoolYear $year): array
    {
        $months = 'January|February|March|April|May|June|July|August|September|October|November|December|Jan\.?|Feb\.?|Mar\.?|Apr\.?|Jun\.?|Jul\.?|Aug\.?|Sep\.?|Sept\.?|Oct\.?|Nov\.?|Dec\.?';

        if (preg_match('/\b('.$months.')\s+(\d{1,2})\s*[-–—]\s*(\d{1,2}),?\s+(20\d{2})\b/i', $line, $match)) {
            return [$this->date($match[1], $match[2], $match[4]), $this->date($match[1], $match[3], $match[4]), $match[0], 0.90];
        }

        if (preg_match('/\b('.$months.')\s+(\d{1,2})\s*[-–—]\s*('.$months.')\s+(\d{1,2}),?\s+(20\d{2})\b/iu', $line, $match)) {
            $startMonth = CarbonImmutable::parse($match[1].' 1')->month;
            $endMonth = CarbonImmutable::parse($match[3].' 1')->month;
            $startYear = $startMonth > $endMonth ? (string) ((int) $match[5] - 1) : $match[5];

            return [$this->date($match[1], $match[2], $startYear), $this->date($match[3], $match[4], $match[5]), $match[0], 0.90];
        }

        preg_match_all('/\b('.$months.')\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(20\d{2})\b/i', $line, $matches, PREG_SET_ORDER);
        if ($matches) {
            return [
                $this->date($matches[0][1], $matches[0][2], $matches[0][3]),
                isset($matches[1]) ? $this->date($matches[1][1], $matches[1][2], $matches[1][3]) : null,
                implode(' - ', array_column($matches, 0)),
                0.92,
            ];
        }

        if (preg_match('/\b(0?[1-9]|1[0-2])[\/.](0?[1-9]|[12]\d|3[01])[\/.](20\d{2}|\d{2})\s*[-–—]\s*(0?[1-9]|1[0-2])[\/.](0?[1-9]|[12]\d|3[01])[\/.](20\d{2}|\d{2})\b/u', $line, $match)) {
            $startYear = strlen($match[3]) === 2 ? '20'.$match[3] : $match[3];
            $endYear = strlen($match[6]) === 2 ? '20'.$match[6] : $match[6];

            return [$this->numericDate($match[1], $match[2], $startYear), $this->numericDate($match[4], $match[5], $endYear), $match[0], 0.75];
        }

        if (preg_match('/\b(0?[1-9]|1[0-2])[\/.](0?[1-9]|[12]\d|3[01])(?:[\/.](20\d{2}|\d{2}))?\b/', $line, $match)) {
            $yearValue = $match[3] ?? null;
            $inferredYear = $yearValue
                ? (strlen($yearValue) === 2 ? '20'.$yearValue : $yearValue)
                : ((int) $match[1] >= $year->start_date->month ? (string) $year->start_date->year : (string) $year->end_date->year);

            return [$this->numericDate($match[1], $match[2], $inferredYear), null, $match[0], $yearValue ? 0.78 : 0.65];
        }

        if (preg_match('/\b('.$months.')\s+(\d{1,2})(?:st|nd|rd|th)?\b/i', $line, $match)) {
            $month = CarbonImmutable::parse($match[1].' 1')->month;
            $inferredYear = $month >= $year->start_date->month ? $year->start_date->year : $year->end_date->year;
            return [$this->date($match[1], $match[2], (string) $inferredYear), null, $match[0], 0.75];
        }

        return [null, null, '', 0.35];
    }

    private function date(string $month, string $day, string $year): ?string
    {
        try { return CarbonImmutable::parse("{$month} {$day}, {$year}")->format('Y-m-d'); }
        catch (\Throwable) { return null; }
    }

    private function numericDate(string $month, string $day, string $year): ?string
    {
        try { return CarbonImmutable::createSafe((int) $year, (int) $month, (int) $day)->format('Y-m-d'); }
        catch (\Throwable) { return null; }
    }

    /** @return array{string, string} */
    private function classify(string $label): array
    {
        $value = mb_strtolower($label);
        return match (true) {
            str_contains($value, 'first day') => ['first_day', 'instructional'],
            str_contains($value, 'last day') => ['last_day', 'instructional'],
            str_contains($value, 'early release') => ['early_release', 'instructional'],
            str_contains($value, 'make-up'), str_contains($value, 'makeup') => ['instructional_makeup_day', 'instructional'],
            str_contains($value, 'teacher work'), str_contains($value, 'staff work') => ['teacher_workday', 'non_instructional'],
            str_contains($value, 'professional development'), str_contains($value, 'staff development') => ['professional_development', 'non_instructional'],
            str_contains($value, 'student holiday') => ['student_holiday', 'non_instructional'],
            str_contains($value, 'break') => ['break', 'non_instructional'],
            str_contains($value, 'no school'), str_contains($value, 'closed'), str_contains($value, 'closure'), str_contains($value, 'bad weather'), str_contains($value, 'inclement') => ['school_closure', 'non_instructional'],
            str_contains($value, 'holiday') => ['holiday', 'non_instructional'],
            default => ['other', 'informational'],
        };
    }

    private function label(string $type): string { return ucwords(str_replace('_', ' ', $type)); }

    /** @param array<int, array{page: int, text: string}> $pages
     *  @return array<string, string>
     */
    private function abbreviations(array $pages): array
    {
        $definitions = [];
        foreach ($pages as $page) {
            foreach (preg_split('/\R+/u', $page['text']) ?: [] as $line) {
                if (preg_match('/^\s*([A-Z][A-Z0-9]{1,5})\s*(?:=|:|[-–—])\s*(.{3,80})\s*$/u', trim($line), $match)
                    && $this->looksRelevant($match[2])) {
                    $definitions[$match[1]] = trim($match[2]);
                }
            }
        }

        return $definitions;
    }

    /** @param array<string, string> $abbreviations */
    private function expandAbbreviations(string $line, array $abbreviations): string
    {
        foreach ($abbreviations as $abbreviation => $meaning) {
            $line = preg_replace('/\b'.preg_quote($abbreviation, '/').'\b/u', $meaning, $line) ?? $line;
        }

        return $line;
    }

    /** @param array<string, string> $abbreviations */
    private function isAbbreviationDefinition(string $line, array $abbreviations): bool
    {
        foreach (array_keys($abbreviations) as $abbreviation) {
            if (preg_match('/^\s*'.preg_quote($abbreviation, '/').'\s*(?:=|:|[-–—])/u', $line)) {
                return true;
            }
        }

        return false;
    }
}
