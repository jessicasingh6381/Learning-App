<?php

namespace App\Services;

use App\Contracts\CalendarProposalParser;
use App\Models\AcademicSource;
use App\Models\SchoolYear;
use Carbon\CarbonImmutable;

final class CyFairDistrictCalendarParser implements CalendarProposalParser
{
    public const VERSION = 'cy-fair-important-dates-v1';

    public function supports(array $pages, AcademicSource $source): bool
    {
        $text = mb_strtolower(collect($pages)->pluck('text')->implode("\n"));
        $identity = mb_strtolower($source->title.' '.($source->currentFile?->original_filename ?? ''));
        $signatures = collect([
            'important dates', 'grading periods', 'district calendar',
            'teacher work day/school closure', 'first and last days of school',
        ])->filter(fn (string $signature) => str_contains($text, $signature))->count();
        $namedCyFair = str_contains(str_replace(['-', ' '], '', $identity), 'cyfair');

        return $signatures >= 4 && ($namedCyFair || str_contains($text, 'teacher work day/school closure make-up day'));
    }

    public function parse(array $pages, SchoolYear $year): array
    {
        $results = [];
        foreach ($pages as $page) {
            $items = collect($page['items'] ?? [])->map(fn (array $item) => [
                ...$item,
                'text' => trim(preg_replace('/\s+/u', ' ', $item['text']) ?? ''),
            ])->filter(fn (array $item) => $item['text'] !== '')->values();
            $heading = $items->search(fn (array $item) => strcasecmp($item['text'], 'IMPORTANT DATES') === 0);
            if ($heading === false) {
                continue;
            }

            $section = $items->slice($heading + 1)->takeUntil(fn (array $item) => in_array(mb_strtolower($item['text']), ['elementary', 'secondary', 'grading periods'], true))->values();
            $currentDate = null;
            $labelParts = [];

            foreach ($section as $item) {
                if ($this->dateRange($item['text'], $year) !== null) {
                    $this->append($results, $currentDate, $labelParts, (int) $page['page']);
                    $currentDate = ['text' => $item['text'], 'range' => $this->dateRange($item['text'], $year)];
                    $labelParts = [];
                } elseif ($currentDate) {
                    $labelParts[] = $item['text'];
                }
            }
            $this->append($results, $currentDate, $labelParts, (int) $page['page']);
        }

        return collect($results)->unique(fn (array $item) => implode('|', [
            $item['event_date'], $item['end_date'], mb_strtolower($item['name']),
        ]))->values()->all();
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function extractionMethod(): string
    {
        return 'pdf_positioned_text';
    }

    private function append(array &$results, ?array $date, array $labelParts, int $page): void
    {
        if (! $date || ! $labelParts) {
            return;
        }

        $label = $this->normalizeLabel(implode(' ', $labelParts));
        if ($label === '') {
            return;
        }
        [$type, $effect] = $this->classify($label);
        $results[] = [
            'event_date' => $date['range'][0],
            'end_date' => $date['range'][1],
            'name' => $label,
            'event_type' => $type,
            'instructional_effect' => $effect,
            'confidence' => 0.98,
            'source_page' => $page,
            'raw_text' => $date['text'].' — '.$label,
            'parser_note' => 'Mapped from the positioned Cy-Fair IMPORTANT DATES column.',
            'included' => true,
        ];
    }

    /** @return array{string, ?string}|null */
    private function dateRange(string $value, SchoolYear $year): ?array
    {
        $months = 'Jan(?:uary)?\.?|Feb(?:ruary)?\.?|Mar(?:ch)?\.?|Apr(?:il)?\.?|May|Jun(?:e)?\.?|Jul(?:y)?\.?|Aug(?:ust)?\.?|Sep(?:t|tember)?\.?|Oct(?:ober)?\.?|Nov(?:ember)?\.?|Dec(?:ember)?\.?';
        if (! preg_match('/^('.$months.')\s+(\d{1,2})(?:\s*[-–—]\s*(?:('.$months.')\s+)?(\d{1,2}))?$/iu', trim($value), $match)) {
            return null;
        }

        $startMonth = $this->month($match[1]);
        $endMonth = isset($match[3]) && $match[3] !== '' ? $this->month($match[3]) : $startMonth;
        $startYear = $startMonth >= $year->start_date->month ? $year->start_date->year : $year->end_date->year;
        $endYear = $endMonth >= $year->start_date->month ? $year->start_date->year : $year->end_date->year;

        try {
            $start = CarbonImmutable::createSafe($startYear, $startMonth, (int) $match[2])->format('Y-m-d');
            $end = isset($match[4]) && $match[4] !== ''
                ? CarbonImmutable::createSafe($endYear, $endMonth, (int) $match[4])->format('Y-m-d')
                : null;

            return [$start, $end];
        } catch (\Throwable) {
            return null;
        }
    }

    private function month(string $value): int
    {
        return CarbonImmutable::parse(rtrim($value, '.').' 1')->month;
    }

    private function normalizeLabel(string $label): string
    {
        $label = preg_replace('/\s*\/\s*/u', '/', $label) ?? $label;
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;

        return trim($label, " \t\n\r\0\x0B/");
    }

    /** @return array{string, string} */
    private function classify(string $label): array
    {
        $value = mb_strtolower($label);

        return match (true) {
            str_contains($value, 'first day') => ['first_day', 'instructional'],
            str_contains($value, 'last day') => ['last_day', 'instructional'],
            str_contains($value, 'teacher work day') => ['teacher_workday', 'non_instructional'],
            str_contains($value, 'professional day') => ['professional_development', 'non_instructional'],
            str_contains($value, 'student/staff holiday') => ['holiday', 'non_instructional'],
            str_contains($value, 'student holiday') => ['student_holiday', 'non_instructional'],
            str_contains($value, 'inclement weather') => ['school_closure', 'non_instructional'],
            default => ['other', 'informational'],
        };
    }
}
