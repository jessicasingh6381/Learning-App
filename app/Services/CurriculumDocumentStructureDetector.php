<?php

namespace App\Services;

use App\Models\AcademicSource;

final class CurriculumDocumentStructureDetector
{
    public function __construct(private StructuredCurriculumUnitHeadingResolver $headingResolver) {}

    public function detect(array $pages, AcademicSource $source): array
    {
        $lines = collect($pages)->flatMap(fn (array $page) => collect(preg_split('/\R/u', (string) ($page['text'] ?? '')) ?: [])->map(fn ($line) => $this->clean($line)))->filter()->values();
        $title = $lines->first();
        $headings = $lines->filter(fn ($line) => $this->isExplicitReportingPeriodHeading($line))->unique()->take(30)->values();
        $unitResolution = $this->headingResolver->resolve($pages);
        $units = collect($unitResolution['units'])->pluck('heading')->values();
        $assessments = $lines->filter(fn ($line) => preg_match('/^(?:Evidence\s+of\s+Learning|Assessment|Final\s+(?:Unit\s+)?Project|Skill\s+Demonstration)(?:\s*[:\-]\s*.+)?$/iu', $line))
            ->reject(fn ($line) => preg_match('/^(?:Assessment\s+Philosophy|Evidence\s+of\s+Learning)$/iu', $line))->unique()->take(50)->values();
        $dates = $lines->filter(fn ($line) => preg_match('/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s*\d{1,2}|\b\d{1,2}[\/-]\d{1,2}\b/iu', $line))->unique()->take(80)->values();
        preg_match_all('/\b\d{1,2}\.\d{1,2}[A-Z]?(?:\s*-\s*\d{1,2}\.\d{1,2}[A-Z]?)?\b/u', $lines->implode("\n"), $standards);
        $columnLabels = collect($pages)->flatMap(fn ($page) => collect($page['items'] ?? [])->pluck('text'))
            ->map(fn ($value) => $this->clean((string) $value))->filter(fn ($value) => in_array(mb_strtolower($value), ['date', 'days', 'unit', 'teks', 'standards', 'skills', 'assessment'], true))->unique()->values();

        return [
            'title' => $title, 'provider' => $source->educationProvider?->name, 'grade' => $source->gradeLevel?->name,
            'subject' => $source->links->firstWhere('link_type', 'subject')?->link_id, 'school_year' => $source->schoolYear?->name,
            'page_count' => count($pages), 'headings' => $headings->all(), 'unit_rows' => $units->all(),
            'unit_ambiguities' => $unitResolution['ambiguities'],
            'assessment_rows' => $assessments->all(), 'date_rows' => $dates->all(),
            'standards_like_codes' => collect($standards[0] ?? [])->unique()->values()->all(), 'column_labels' => $columnLabels->all(),
            'suggested_strategy' => collect(['date', 'days', 'unit'])->every(fn ($label) => $columnLabels->map(fn ($value) => mb_strtolower($value))->contains($label))
                ? 'positioned_date_unit_table' : 'confirmed_heading_rows',
        ];
    }

    public function fingerprints(array $detected, array $confirmedHeadings): array
    {
        return ['page_count' => $detected['page_count'], 'required_text' => collect([$detected['title'], ...$confirmedHeadings])->filter()->unique()->take(8)->values()->all(), 'required_columns' => $detected['column_labels']];
    }

    private function clean(string $value): string { return trim(preg_replace('/\s+/u', ' ', $value) ?? $value); }

    private function isExplicitReportingPeriodHeading(string $line): bool
    {
        return (bool) preg_match(
            '/^(?:(?:1st|2nd|3rd|4th|first|second|third|fourth)\s+(?:grading|reporting)\s+period|(?:grading|reporting)\s+period\s+[1-9]\d*|(?:1st|2nd|3rd|4th|first|second|third|fourth)\s+(?:six|nine)\s+weeks|(?:six|nine)\s+weeks\s+[1-9]\d*|quarter\s+[1-4]|semester\s+[1-2])(?:\s*[:\-\x{2013}\x{2014}]\s*.+)?$/iu',
            $line,
        );
    }
}
