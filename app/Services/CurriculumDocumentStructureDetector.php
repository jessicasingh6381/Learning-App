<?php

namespace App\Services;

use App\Models\AcademicSource;

final class CurriculumDocumentStructureDetector
{
    public function detect(array $pages, AcademicSource $source): array
    {
        $lines = collect($pages)->flatMap(fn (array $page) => collect(preg_split('/\R/u', (string) ($page['text'] ?? '')) ?: [])->map(fn ($line) => $this->clean($line)))->filter()->values();
        $title = $lines->first();
        $headings = $lines->filter(fn ($line) => preg_match('/(?:grading period|nine weeks|semester|quarter|year at a glance|scope and sequence|pacing guide)/iu', $line))->unique()->take(30)->values();
        $units = $lines->filter(fn ($line) => preg_match('/\bunit\b/iu', $line))->unique()->take(80)->values();
        $assessments = $lines->filter(fn ($line) => preg_match('/\b(?:assessment|benchmark|DPM|MAP|STAAR|CCA|exam|test)\b/iu', $line))->unique()->take(50)->values();
        $dates = $lines->filter(fn ($line) => preg_match('/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s*\d{1,2}|\b\d{1,2}[\/-]\d{1,2}\b/iu', $line))->unique()->take(80)->values();
        preg_match_all('/\b\d{1,2}\.\d{1,2}[A-Z]?(?:\s*-\s*\d{1,2}\.\d{1,2}[A-Z]?)?\b/u', $lines->implode("\n"), $standards);
        $columnLabels = collect($pages)->flatMap(fn ($page) => collect($page['items'] ?? [])->pluck('text'))
            ->map(fn ($value) => $this->clean((string) $value))->filter(fn ($value) => in_array(mb_strtolower($value), ['date', 'days', 'unit', 'teks', 'standards', 'skills', 'assessment'], true))->unique()->values();

        return [
            'title' => $title, 'provider' => $source->educationProvider?->name, 'grade' => $source->gradeLevel?->name,
            'subject' => $source->links->firstWhere('link_type', 'subject')?->link_id, 'school_year' => $source->schoolYear?->name,
            'page_count' => count($pages), 'headings' => $headings->all(), 'unit_rows' => $units->all(),
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
}
