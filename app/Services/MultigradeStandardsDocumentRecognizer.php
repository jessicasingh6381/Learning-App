<?php

namespace App\Services;

use App\Models\AcademicSource;
use App\Models\Subject;
use Illuminate\Support\Str;

final class MultigradeStandardsDocumentRecognizer
{
    public function isTexasElementarySocialStudies(array $pages): bool
    {
        $text = collect($pages)->pluck('text')->implode("\n");
        return str_contains($text, 'Chapter 113. Texas Essential Knowledge and Skills for Social Studies')
            && str_contains($text, 'Subchapter A. Elementary')
            && str_contains($text, '§113.11. Social Studies, Kindergarten')
            && str_contains($text, '§113.16. Social Studies, Grade 5')
            && str_contains($text, '(a) Implementation.') && str_contains($text, '(b) Introduction.')
            && str_contains($text, '(c) Knowledge and skills.');
    }

    public function context(AcademicSource $source): ?array
    {
        $source->loadMissing(['gradeLevel', 'links']);
        $subjectId = $source->links->firstWhere('link_type', 'subject')?->link_id;
        $subject = $subjectId ? Subject::query()->find($subjectId) : null;
        $subjectValues = [$this->normalize($subject?->code), $this->normalize($subject?->name)];
        if (! array_intersect($subjectValues, ['SS', 'SOCST', 'SOCIALSTUDIES'])) return null;
        $grade = $this->gradeLabel($source->gradeLevel?->code, $source->gradeLevel?->name);
        return $grade ? ['subject' => 'Social Studies', 'grade' => $grade] : null;
    }

    public function sections(array $pages): array
    {
        $sections = [];
        foreach ($pages as $index => $page) {
            preg_match_all('/§(?<section>113\.\d+)\.\s+(?<subject>Social Studies),\s+(?<grade>Kindergarten|Grade\s+\d+),\s+Adopted\s+(?<adopted>\d{4})\./u', (string) ($page['text'] ?? ''), $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $matchIndex => [$heading, $offset]) {
                $sections[] = [
                    'section' => $matches['section'][$matchIndex][0], 'subject' => $matches['subject'][$matchIndex][0],
                    'grade' => preg_replace('/\s+/', ' ', $matches['grade'][$matchIndex][0]),
                    'adopted' => $matches['adopted'][$matchIndex][0], 'heading' => trim($heading),
                    'page' => $index + 1, 'page_index' => $index, 'offset' => $offset,
                ];
            }
        }
        return $sections;
    }

    public function matchingSections(array $pages, AcademicSource $source): array
    {
        $context = $this->context($source);
        if (! $context || ! $this->isTexasElementarySocialStudies($pages)) return [];
        return collect($this->sections($pages))->where('subject', $context['subject'])->where('grade', $context['grade'])->values()->all();
    }

    public function isolatedSection(array $pages, array $match): array
    {
        $parts = []; $offsets = []; $cursor = 0;
        for ($index = $match['page_index']; $index < count($pages); $index++) {
            $text = (string) ($pages[$index]['text'] ?? '');
            $nextFound = false; $sourceFound = false;
            if ($index === $match['page_index']) {
                $text = substr($text, $match['offset']);
                preg_match_all('/§113\.\d+\.\s+Social Studies,\s+(?:Kindergarten|Grade\s+\d+),/u', $text, $samePageSections, PREG_OFFSET_CAPTURE);
                if (count($samePageSections[0]) > 1) {
                    $text = substr($text, 0, $samePageSections[0][1][1]); $nextFound = true;
                }
            }
            elseif (preg_match('/§113\.\d+\.\s+Social Studies,\s+(?:Kindergarten|Grade\s+\d+),/u', $text, $next, PREG_OFFSET_CAPTURE)) {
                $text = substr($text, 0, $next[0][1]); $nextFound = true;
            }
            $text = preg_replace('/^Elementary\s+§113\.A\.\s*\RAugust 2024 Update\s+Page\s+\d+\s+of\s+\d+\s*/u', '', $text) ?? $text;
            if (preg_match('/\bSource:\s+The provisions of this §113\./u', $text, $sourceLine, PREG_OFFSET_CAPTURE)) {
                $text = substr($text, 0, $sourceLine[0][1]); $sourceFound = true;
            }
            $text = trim(preg_replace('/[\t ]+/u', ' ', $text) ?? $text);
            if ($text !== '') {
                if ($parts !== []) $cursor++;
                $offsets[] = ['start' => $cursor, 'end' => $cursor + strlen($text), 'page' => $index + 1];
                $parts[] = $text; $cursor += strlen($text);
            }
            if ($nextFound || $sourceFound) break;
        }
        return ['text' => implode("\n", $parts), 'pages' => $offsets, 'start_page' => $match['page'], 'end_page' => end($offsets)['page'] ?? $match['page']];
    }

    public function pageForOffset(array $isolated, int $offset): int
    {
        foreach ($isolated['pages'] as $page) if ($offset >= $page['start'] && $offset <= $page['end']) return $page['page'];
        return $isolated['start_page'];
    }

    private function gradeLabel(?string $code, ?string $name): ?string
    {
        $value = $this->normalize(($code ?? '').' '.($name ?? ''));
        if (str_contains($value, 'KINDERGARTEN') || in_array($this->normalize($code), ['K', 'KG'], true)) return 'Kindergarten';
        if (preg_match('/(?:GRADE|G)0?([1-5])/', $value, $match)) return 'Grade '.$match[1];
        return null;
    }

    private function normalize(?string $value): string { return Str::upper(preg_replace('/[^a-z0-9]+/i', '', (string) $value) ?? ''); }
}
