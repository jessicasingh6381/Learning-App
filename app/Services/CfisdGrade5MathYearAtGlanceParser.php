<?php

namespace App\Services;

use App\Contracts\CurriculumOutlineParser;
use App\Data\CurriculumParserResult;
use App\Data\CurriculumParserApplicability;
use App\Data\CurriculumProposalData;
use App\Models\AcademicSource;
use App\Models\Subject;
use Carbon\CarbonImmutable;

final class CfisdGrade5MathYearAtGlanceParser implements CurriculumOutlineParser
{
    public const KEY = 'cfisd-grade5-math-yag';
    public const VERSION = 'cfisd-grade5-math-yag-v1';

    public function supports(array $pages, AcademicSource $source): bool
    {
        return $this->recognitionScore($pages, $source) > 0;
    }

    public function recognitionScore(array $pages, AcademicSource $source): float
    {
        if (! $this->sourceContextMatches($source)) return 0.0;
        $text = mb_strtolower(collect($pages)->pluck('text')->implode("\n"));
        if (! str_contains($text, 'grade 5 math')
            || ! str_contains($text, 'year at a glance')
            || ! str_contains($text, '1st nine weeks')
            || ! str_contains($text, 'assessments at a glance')) return 0.0;

        $items = collect($pages)->flatMap(fn (array $page) => $page['items'] ?? []);
        $periodHeadings = $items->filter(fn (array $item) => preg_match('/^\d+(?:st|nd|rd|th)\s+Nine Weeks\s+.+[-\x{2013}\x{2014}].+$/iu', trim((string) ($item['text'] ?? ''))));
        $assessmentHeading = $items->contains(fn (array $item) => str_contains(mb_strtolower((string) ($item['text'] ?? '')), 'assessments at a glance'));
        $datedCells = $items->filter(fn (array $item) => $this->looksLikeDate((string) ($item['text'] ?? '')));

        return $periodHeadings->isNotEmpty() && $assessmentHeading && $datedCells->count() >= 2 ? .99 : 0.0;
    }

    public function applicability(): CurriculumParserApplicability
    {
        return new CurriculumParserApplicability(
            providerCodes: ['CFISD', 'Cypress-Fairbanks Independent School District'],
            subjectCodes: ['MATH'],
            gradeCodes: ['G5', 'Grade 5'],
            sourceCategories: ['curriculum', 'pacing', 'scope_and_sequence'],
            mimeTypes: ['application/pdf'],
            extensions: ['pdf'],
            documentFamily: 'Year at a Glance',
            priority: 100,
        );
    }

    public function parse(array $pages, AcademicSource $source): CurriculumParserResult
    {
        $text = collect($pages)->pluck('text')->implode("\n");
        preg_match('/Grade\s+(\d+)\s+Math\s+Year at a Glance\s+(\d{4}\s*[-\x{2013}\x{2014}]\s*\d{4})/iu', $text, $titleMatch);
        preg_match('/revised\s+(\d{1,2})-(\d{1,2})-(\d{2,4})/iu', $text, $revisionMatch);
        $title = trim($titleMatch[0] ?? $source->title);
        $revisionDate = $revisionMatch ? $this->revisionDate($revisionMatch) : null;
        $proposals = [];
        $unitSequence = 0;

        foreach ($pages as $page) {
            $items = collect($page['items'] ?? [])->map(fn (array $item) => [
                ...$item,
                'text' => trim(preg_replace('/\s+/u', ' ', $item['text']) ?? ''),
            ])->filter(fn (array $item) => $item['text'] !== '')->values();
            $periods = $items->filter(fn (array $item) => preg_match('/^\d+(?:st|nd|rd|th)\s+Nine Weeks\b/iu', $item['text']))
                ->sortByDesc('y')->values();

            foreach ($periods as $periodIndex => $heading) {
                if (! preg_match('/^(\d+(?:st|nd|rd|th)\s+Nine Weeks)\s+(.+?)\s*[-\x{2013}\x{2014}]\s*(.+)$/iu', $heading['text'], $match)) {
                    continue;
                }
                $periodName = $match[1];
                [$periodStart, $periodEnd] = $this->dateRange($match[2].' - '.$match[3], $source);
                $periodKey = 'period:'.($periodIndex + 1);
                $proposals[] = new CurriculumProposalData(
                    $periodKey, null, 'period', $periodIndex + 1, $periodName,
                    $periodStart, $periodEnd, unitType: null, reportingPeriod: $periodName,
                    sourcePage: (int) $page['page'], rawText: $heading['text'],
                    parserNote: 'Mapped from a positioned reporting-period heading.', confidence: .99,
                );

                $lowerY = isset($periods[$periodIndex + 1]) ? (float) $periods[$periodIndex + 1]['y'] : (float) $heading['y'] - 155;
                $band = $items->filter(fn (array $item) => (float) $item['y'] < (float) $heading['y'] && (float) $item['y'] > $lowerY)->values();
                $dates = $band->filter(fn (array $item) => (float) $item['y'] > (float) $heading['y'] - 35 && $this->looksLikeDate($item['text']))
                    ->sortBy('x')->values();
                foreach ($dates as $dateIndex => $date) {
                    $left = $dateIndex === 0 ? -INF : (((float) $dates[$dateIndex - 1]['x'] + (float) $date['x']) / 2);
                    $right = $dateIndex === $dates->count() - 1 ? INF : (((float) $date['x'] + (float) $dates[$dateIndex + 1]['x']) / 2);
                    $cell = $band->filter(fn (array $item) => (float) $item['x'] >= $left && (float) $item['x'] < $right)->values();
                    $nameParts = $cell->filter(fn (array $item) => (float) $item['y'] <= (float) $date['y'] + 2
                        && (float) $item['y'] >= (float) $date['y'] - 65
                        && ! $this->looksLikeDate($item['text'])
                        && ! preg_match('/^(?:DATE|SCOPE|TEKS|Tech App\.)$/iu', $item['text'])
                        && ! preg_match('/^\((?:\d+\s+days?|cont|remainder)\)$/iu', $item['text']))
                        ->sortByDesc('y')->pluck('text');
                    $name = trim(preg_replace('/\s+/u', ' ', $nameParts->implode(' ')) ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $cellText = $cell->pluck('text')->implode(' ');
                    preg_match('/\((\d+)\s+days?\)/iu', $cellText, $daysMatch);
                    [$start, $end] = $this->dateRange($date['text'], $source);
                    $codes = $this->standardCodes($cellText);
                    $type = $this->unitType($name);
                    $note = match (true) {
                        $name === 'Bridge to 5th Grade' => 'Source says “Bridge to 5th Grade”; preserved exactly and flagged for review.',
                        str_contains(mb_strtolower($cellText), '(cont)') => 'Source marks this block as continued; estimated days were not inferred.',
                        str_contains(mb_strtolower($cellText), '(remainder)') => 'Source assigns the remainder of the period; estimated days were not inferred.',
                        default => 'Mapped from a positioned curriculum unit cell.',
                    };
                    $confidence = $name === 'Bridge to 5th Grade' ? .55 : (str_contains($cellText, '(cont)') || str_contains($cellText, '(remainder)') ? .75 : .96);
                    $unitSequence++;
                    $proposals[] = new CurriculumProposalData(
                        'unit:'.$unitSequence, $periodKey, $type === 'assessment' ? 'assessment' : 'unit',
                        $unitSequence, $name, $start, $end, isset($daysMatch[1]) ? (int) $daysMatch[1] : null,
                        $type, $periodName, $codes, (int) $page['page'],
                        trim($date['text'].' — '.$cellText), $note, $confidence,
                    );
                }
            }

            $assessmentHeading = $items->first(fn (array $item) => str_contains(mb_strtolower($item['text']), 'assessments at a glance'));
            if ($assessmentHeading) {
                $assessmentBand = $items->filter(fn (array $item) => (float) $item['y'] <= (float) $assessmentHeading['y']
                    && (float) $item['y'] >= (float) $assessmentHeading['y'] - 70)->values();
                $dates = $assessmentBand->filter(fn (array $item) => $this->looksLikeDate($item['text']))->sortBy('x')->values();
                foreach ($dates as $dateIndex => $date) {
                    $left = $dateIndex === 0 ? -INF : (((float) $dates[$dateIndex - 1]['x'] + (float) $date['x']) / 2);
                    $right = $dateIndex === $dates->count() - 1 ? INF : (((float) $date['x'] + (float) $dates[$dateIndex + 1]['x']) / 2);
                    $name = $assessmentBand->filter(fn (array $item) => (float) $item['x'] >= $left && (float) $item['x'] < $right
                        && (float) $item['y'] >= (float) $date['y']
                        && ! $this->looksLikeDate($item['text'])
                        && ! preg_match('/^(?:SEP|OCT|NOV|DEC|JAN|FEB|MAR|APR|MAY)$/iu', $item['text'])
                        && ! str_contains(mb_strtolower($item['text']), 'assessments at a glance'))
                        ->sortByDesc('y')->pluck('text')->implode(' ');
                    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
                    if ($name === '') {
                        continue;
                    }
                    [$start, $end] = $this->dateRange($date['text'], $source);
                    $parent = $this->periodForDate($proposals, $start);
                    $unitSequence++;
                    $proposals[] = new CurriculumProposalData(
                        'assessment:'.$unitSequence, $parent?->key, 'assessment', $unitSequence, $name,
                        $start, $end, unitType: 'assessment', reportingPeriod: $parent?->name,
                        sourcePage: (int) $page['page'], rawText: $name.' — '.$date['text'],
                        parserNote: 'Mapped from the Assessments at a Glance timeline.', confidence: .95,
                    );
                }
            }
        }

        $periodCount = collect($proposals)->where('proposalType', 'period')->count();
        $unitCount = collect($proposals)->whereIn('proposalType', ['unit', 'assessment'])->count();
        $diagnostic = $periodCount === 4 && $unitCount >= 20
            ? null
            : "Extraction needs review: {$periodCount} reporting periods and {$unitCount} unit/assessment blocks were recognized.";

        return new CurriculumParserResult(
            $title,
            $revisionDate,
            isset($titleMatch[1]) ? 'Grade '.$titleMatch[1] : null,
            isset($titleMatch[1]) ? 'Mathematics' : null,
            isset($titleMatch[2]) ? preg_replace('/\s+/u', '', $titleMatch[2]) : null,
            $proposals,
            $diagnostic,
        );
    }

    public function key(): string { return self::KEY; }
    public function version(): string { return self::VERSION; }
    public function extractionMethod(): string { return 'pdf_positioned_text'; }

    private function sourceContextMatches(AcademicSource $source): bool
    {
        $source->loadMissing(['educationProvider', 'gradeLevel', 'links']);
        $subjectId = $source->links->firstWhere('link_type', 'subject')?->link_id;
        if (! $subjectId || Subject::query()->whereKey($subjectId)->value('code') !== 'MATH') return false;
        if (! in_array($source->gradeLevel?->code, ['G5', '5'], true) && $source->gradeLevel?->name !== 'Grade 5') return false;
        if ($source->educationProvider && ! in_array($source->educationProvider->short_name, ['CFISD'], true)
            && $source->educationProvider->name !== 'Cypress-Fairbanks Independent School District') return false;

        return in_array($source->source_category, ['curriculum', 'pacing', 'scope_and_sequence'], true);
    }

    private function looksLikeDate(string $value): bool
    {
        return (bool) preg_match('/^(?:JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEPT?|OCT|NOV|DEC)[A-Z]*\s+\d{1,2}(?:\s*[-\x{2013}\x{2014}]\s*(?:(?:JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEPT?|OCT|NOV|DEC)[A-Z]*\s+)?\d{1,2})?$/iu', trim($value));
    }

    private function dateRange(string $value, AcademicSource $source): array
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if (! preg_match('/^([A-Za-z]+)\s+(\d{1,2})(?:\s*[-\x{2013}\x{2014}]\s*(?:([A-Za-z]+)\s+)?(\d{1,2}))?$/u', $value, $match)) {
            return [null, null];
        }
        $year = $source->schoolYear;
        $startMonth = CarbonImmutable::parse($match[1].' 1')->month;
        $endMonth = ! empty($match[3]) ? CarbonImmutable::parse($match[3].' 1')->month : $startMonth;
        $startYear = $year ? ($startMonth >= $year->start_date->month ? $year->start_date->year : $year->end_date->year) : (int) substr($source->academic_year_label ?? date('Y'), 0, 4);
        $endYear = $year ? ($endMonth >= $year->start_date->month ? $year->start_date->year : $year->end_date->year) : $startYear;
        $start = CarbonImmutable::createSafe($startYear, $startMonth, (int) $match[2])->format('Y-m-d');
        $end = ! empty($match[4]) ? CarbonImmutable::createSafe($endYear, $endMonth, (int) $match[4])->format('Y-m-d') : $start;

        return [$start, $end];
    }

    private function standardCodes(string $text): array
    {
        $text = preg_replace('/(5\.\d{1,2})\s+([A-Z])\b/u', '$1$2', strtoupper($text)) ?? strtoupper($text);
        preg_match_all('/5\.\d{1,2}[A-Z](?:\s*[-\x{2013}\x{2014}]\s*5\.\d{1,2}[A-Z])?/u', $text, $matches);
        $codes = [];
        foreach ($matches[0] as $code) {
            $clean = preg_replace('/\s+/u', '', $code) ?? $code;
            if (preg_match('/^(5\.\d{1,2})([A-Z])[-\x{2013}\x{2014}](5\.\d{1,2})([A-Z])$/u', $clean, $range)
                && $range[1] === $range[3] && ord($range[4]) >= ord($range[2]) && ord($range[4]) - ord($range[2]) <= 12) {
                foreach (range(ord($range[2]), ord($range[4])) as $letter) {
                    $codes[] = $range[1].chr($letter);
                }
            } else {
                $codes[] = $clean;
            }
        }

        return array_values(array_unique($codes));
    }

    private function unitType(string $name): string
    {
        $value = mb_strtolower($name);

        return match (true) {
            str_contains($value, 'assessment'), str_contains($value, 'benchmark'), str_contains($value, 'dpm'), str_contains($value, 'staar') && ! str_contains($value, 'review') => 'assessment',
            str_contains($value, 'review') => 'review',
            str_contains($value, 'bridge'), str_contains($value, 'transition') => 'transition',
            default => 'instructional',
        };
    }

    private function periodForDate(array $proposals, ?string $date): ?CurriculumProposalData
    {
        if (! $date) return null;
        return collect($proposals)->first(fn (CurriculumProposalData $proposal) => $proposal->proposalType === 'period'
            && $proposal->plannedStartDate <= $date && $proposal->plannedEndDate >= $date);
    }

    private function revisionDate(array $match): ?string
    {
        $year = (int) $match[3];
        if ($year < 100) $year += 2000;
        try { return CarbonImmutable::createSafe($year, (int) $match[1], (int) $match[2])->format('Y-m-d'); }
        catch (\Throwable) { return null; }
    }
}
