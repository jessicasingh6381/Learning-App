<?php

namespace App\Services;

use App\Contracts\CurriculumOutlineParser;
use App\Data\CurriculumParserApplicability;
use App\Data\CurriculumParserResult;
use App\Data\CurriculumProposalData;
use App\Models\AcademicSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CfisdGrade5ScienceYearAtGlanceParser implements CurriculumOutlineParser
{
    public const KEY = 'cfisd-grade5-science-yag';
    public const VERSION = 'cfisd-grade5-science-yag-v1';

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    public function __construct(private CfisdYearAtGlanceFamilyRecognizer $family) {}

    public function supports(array $pages, AcademicSource $source): bool { return $this->recognitionScore($pages, $source) > 0; }

    public function recognitionScore(array $pages, AcademicSource $source): float
    {
        if (! $this->family->sourceMatches($source, ['SCI'], ['5', 'G5', 'Grade 5']) || count($pages) !== 2) return 0.0;
        $text = mb_strtolower(collect($pages)->pluck('text')->implode("\n"));
        if (! str_contains($text, '5th grade science year at a glance 2025-2026')
            || ! str_contains($text, 'grade 5 science assessments at a glance')
            || ! str_contains($text, 'earth processes') || ! str_contains($text, 'stem/pbl unit')) return 0.0;
        if (! $this->family->hasPositionedColumns($pages, ['Date', 'Days', 'Unit', 'TEKS'])) return 0.0;
        foreach (['1st Nine Weeks', '2nd Nine Weeks', '3rd Nine Weeks', '4th Nine Weeks'] as $heading) {
            if (! str_contains($text, mb_strtolower($heading))) return 0.0;
        }
        return .997;
    }

    public function applicability(): CurriculumParserApplicability
    {
        return new CurriculumParserApplicability(
            providerCodes: ['CFISD', 'Cypress-Fairbanks Independent School District'], subjectCodes: ['SCI'],
            gradeCodes: ['5', 'G5', 'Grade 5'], sourceCategories: ['curriculum', 'pacing', 'scope_and_sequence'],
            mimeTypes: ['application/pdf'], extensions: ['pdf'], documentFamily: 'CFISD Elementary Science Year at a Glance', priority: 100,
        );
    }

    public function parse(array $pages, AcademicSource $source): CurriculumParserResult
    {
        if (! $this->supports($pages, $source)) throw new \RuntimeException('The PDF does not match the supported CFISD Grade 5 Science layout.');
        $periods = []; $occurrences = [];
        foreach ($pages as $page) {
            $pageNumber = (int) ($page['page'] ?? 1);
            $items = collect($page['items'] ?? [])->map(fn (array $item) => [...$item, 'text' => $this->clean((string) ($item['text'] ?? ''))])->filter(fn ($item) => $item['text'] !== '')->values();
            $headings = $items->filter(fn ($item) => (float) $item['x'] < 120 && preg_match('/^[1-4](?:st|nd|rd|th) Nine/iu', $item['text']))
                ->map(function ($item) use ($items, $pageNumber) {
                    $line = $this->lineAt($items, (float) $item['y']);
                    preg_match('/([1-4])(?:st|nd|rd|th) Nine Weeks/iu', $line, $sequence);
                    [$start, $end] = $this->headingDates($line);
                    return ['sequence' => (int) $sequence[1], 'name' => $sequence[1].$this->suffix((int) $sequence[1]).' Nine Weeks', 'start' => $start, 'end' => $end, 'y' => (float) $item['y'], 'page' => $pageNumber, 'raw' => $line];
                })->keyBy('sequence');
            foreach ($headings as $sequence => $heading) $periods[$sequence] = $heading;

            $rowItems = $items->filter(fn ($item) => (float) $item['x'] < 190 && preg_match('/^(?:JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEPT|OCT|NOV|DEC)\./u', $item['text']))->sortByDesc('y')->values();
            foreach ($rowItems as $index => $dateItem) {
                $y = (float) $dateItem['y'];
                $heading = $headings->filter(fn ($candidate) => $candidate['y'] > $y)->sortBy('y')->first();
                if (! $heading) continue;
                $nextRowY = isset($rowItems[$index + 1]) ? (float) $rowItems[$index + 1]['y'] : -INF;
                $nextHeadingY = $headings->filter(fn ($candidate) => $candidate['y'] < $y)->max('y') ?? -INF;
                $lower = max($nextRowY, $nextHeadingY);
                $dateRaw = $this->column($items, $y, $y - .6, 80, 190);
                $daysRaw = $this->column($items, $y, $y - .6, 190, 250);
                $unitRaw = $this->column($items, $y + .6, $lower + .6, 280, 405);
                $teksRaw = $this->column($items, $y + .6, $lower + .6, 405, INF);
                if (! $unitRaw || ! preg_match('/^\d+$/', $daysRaw)) continue;
                [$start, $end] = $this->rowDates($dateRaw, $heading['start'], $heading['end']);
                $name = $this->clean(preg_replace('/\s*\(TEKS\s+[^)]+\)\s*/iu', ' ', $unitRaw) ?? $unitRaw);
                $codes = $this->standardCodes($teksRaw ?: $unitRaw);
                $occurrences[] = compact('name', 'start', 'end', 'codes') + [
                    'days' => (int) $daysRaw, 'period' => $heading['sequence'], 'page' => $pageNumber,
                    'raw' => $this->clean("{$dateRaw} | {$daysRaw} days | {$unitRaw} | {$teksRaw}"),
                ];
            }
        }
        ksort($periods);
        $proposals = [];
        foreach ($periods as $period) $proposals[] = new CurriculumProposalData(
            "period:{$period['sequence']}", null, 'period', $period['sequence'], $period['name'], $period['start'], $period['end'],
            reportingPeriod: $period['name'], sourcePage: $period['page'], rawText: $period['raw'],
            parserNote: 'Mapped from the positioned Science nine-weeks heading and printed date range.', confidence: .99,
        );

        $units = collect($occurrences)->groupBy(fn ($row) => Str::lower(preg_replace('/[^a-z0-9]+/i', '', $row['name']) ?? $row['name']))->values();
        foreach ($units as $sequence => $rows) {
            $first = $rows->first(); $pagesUsed = $rows->pluck('page')->unique()->values()->all();
            $unitKey = 'unit:'.($sequence + 1);
            $proposals[] = new CurriculumProposalData(
                $unitKey, "period:{$first['period']}", 'unit', $sequence + 1, $first['name'], $rows->min('start'), $rows->max('end'),
                estimatedDays: $rows->sum('days'), unitType: str_contains(mb_strtolower($first['name']), 'review') ? 'review' : 'instructional',
                reportingPeriod: $periods[$first['period']]['name'], standardCodes: $rows->flatMap(fn ($row) => $row['codes'])->unique()->values()->all(),
                sourcePage: min($pagesUsed), rawText: $rows->pluck('raw')->implode(' || '),
                parserNote: $rows->count() > 1 ? 'Continuous positioned unit rows across reporting periods were merged.' : 'Mapped from one positioned Date / Days / Unit / TEKS row.',
                confidence: $rows->count() > 1 ? .94 : .99, parserMetadata: ['source_pages' => $pagesUsed, 'row_count' => $rows->count()],
                summary: $first['name'],
            );
            $proposals[] = new CurriculumProposalData(
                "{$unitKey}:concept", $unitKey, 'component', 1, $first['name'], $rows->min('start'), $rows->max('end'),
                sourcePage: min($pagesUsed), rawText: $rows->pluck('raw')->implode(' || '),
                parserNote: 'The printed Science unit title is retained as a queryable content concept.', confidence: .98,
                parserMetadata: ['source_pages' => $pagesUsed], componentType: 'concept',
            );
        }

        foreach ($this->assessments($pages, $periods) as $index => $assessment) $proposals[] = new CurriculumProposalData(
            'assessment:'.Str::slug($assessment['name']), "period:{$assessment['period']}", 'assessment', 100 + $index + 1,
            $assessment['name'], $assessment['start'], $assessment['end'], unitType: 'assessment', reportingPeriod: $periods[$assessment['period']]['name'],
            sourcePage: $assessment['page'], rawText: $assessment['raw'], parserNote: 'Mapped from the Science Assessments at a Glance list.', confidence: .99,
        );

        $sourceYear = $source->schoolYear?->name;
        return new CurriculumParserResult(
            '5th Grade Science Year at a Glance 2025-2026', null, 'Grade 5', 'Science', '2025-2026', $proposals,
            $sourceYear && $sourceYear !== '2025-2026' ? "The PDF is labeled 2025-2026 while the source is assigned to {$sourceYear}; verify dates during review." : null,
        );
    }

    public function key(): string { return self::KEY; }
    public function version(): string { return self::VERSION; }
    public function extractionMethod(): string { return 'pdf_positioned_text'; }

    private function assessments(array $pages, array $periods): array
    {
        $results = [];
        foreach ($pages as $page) foreach (preg_split('/\R/u', (string) ($page['text'] ?? '')) ?: [] as $line) {
            $line = $this->clean($line);
            if (! preg_match('/^(MAP BOY|DPM 1|DPM 2|Benchmark|CCA|Science STAAR|MAP EOY):\s*([A-Za-z]+)\.?\s*(\d{1,2})(?:\s*\D+\s*(\d{1,2}))?$/u', $line, $match)) continue;
            $month = self::MONTHS[mb_strtolower(substr($match[2], 0, 4))] ?? self::MONTHS[mb_strtolower(substr($match[2], 0, 3))] ?? null;
            if (! $month) continue;
            $year = $month >= 8 ? 2025 : 2026;
            $start = CarbonImmutable::createSafe($year, $month, (int) $match[3])->format('Y-m-d');
            $endDay = ($match[4] ?? '') !== '' ? (int) $match[4] : (int) $match[3];
            $end = CarbonImmutable::createSafe($year, $month, $endDay)->format('Y-m-d');
            $period = collect($periods)->first(fn ($candidate) => $start >= $candidate['start'] && $start <= $candidate['end'])['sequence'] ?? 1;
            $results[] = ['name' => $match[1], 'start' => $start, 'end' => $end, 'period' => $period, 'page' => (int) $page['page'], 'raw' => $line];
        }
        return $results;
    }

    private function lineAt(Collection $items, float $y): string { return $this->clean($items->filter(fn ($item) => abs((float) $item['y'] - $y) < .7)->sortBy('x')->pluck('text')->implode(' ')); }
    private function column(Collection $items, float $top, float $bottom, float $left, float $right): string { return $this->clean($items->filter(fn ($item) => (float) $item['y'] <= $top && (float) $item['y'] > $bottom && (float) $item['x'] >= $left && (float) $item['x'] < $right)->sort(fn ($a, $b) => abs((float) $a['y'] - (float) $b['y']) < .7 ? ((float) $a['x'] <=> (float) $b['x']) : ((float) $b['y'] <=> (float) $a['y']))->pluck('text')->implode(' ')); }

    private function headingDates(string $line): array
    {
        preg_match('/([A-Za-z]+)\s+(\d{1,2})\s*-\s*([A-Za-z]+)\s+(\d{1,2}),\s*(\d{4})/u', $line, $match);
        $startMonth = $this->month($match[1]); $endMonth = $this->month($match[3]); $endYear = (int) $match[5];
        $startYear = $startMonth > $endMonth ? $endYear - 1 : $endYear;
        return [CarbonImmutable::createSafe($startYear, $startMonth, (int) $match[2])->format('Y-m-d'), CarbonImmutable::createSafe($endYear, $endMonth, (int) $match[4])->format('Y-m-d')];
    }

    private function rowDates(string $raw, string $periodStart, string $periodEnd): array
    {
        preg_match('/([A-Z]+)\.(\d{1,2})(?:\s*-\s*([A-Z]+)\.(\d{1,2}))?/u', $raw, $match);
        $startMonth = $this->month($match[1]); $endMonth = isset($match[3]) && $match[3] !== '' ? $this->month($match[3]) : $startMonth;
        $periodStartYear = (int) substr($periodStart, 0, 4); $periodEndYear = (int) substr($periodEnd, 0, 4);
        $startYear = $startMonth >= (int) substr($periodStart, 5, 2) ? $periodStartYear : $periodEndYear;
        $endYear = $endMonth < $startMonth ? $startYear + 1 : $startYear;
        return [CarbonImmutable::createSafe($startYear, $startMonth, (int) $match[2])->format('Y-m-d'), CarbonImmutable::createSafe($endYear, $endMonth, (int) ($match[4] ?? $match[2]))->format('Y-m-d')];
    }

    private function standardCodes(string $text): array { preg_match_all('/5\.\d+[A-Z]?(?:\s*-\s*5\.\d+[A-Z]?)?/u', $text, $matches); return collect($matches[0])->map(fn ($code) => preg_replace('/\s+/', '', $code))->unique()->values()->all(); }
    private function month(string $value): int { $key = mb_strtolower(substr(trim($value), 0, 4)); return self::MONTHS[$key] ?? self::MONTHS[substr($key, 0, 3)] ?? throw new \RuntimeException("Unrecognized month {$value}."); }
    private function suffix(int $number): string { return match ($number) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' }; }
    private function clean(string $value): string { return trim(preg_replace('/\s+/u', ' ', $value) ?? $value); }
}
