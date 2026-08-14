<?php

namespace App\Services;

final class StructuredCurriculumUnitHeadingResolver
{
    private const HEADING = '/^Unit\s+(\d+)\s*([-\x{2013}\x{2014}:])\s*(\S.+)$/iu';

    /** @return array{entries: array<int, array<string, mixed>>, units: array<int, array<string, mixed>>, ambiguities: array<int, array<string, mixed>>} */
    public function resolve(array $pages): array
    {
        $entries = $this->entries($pages);
        $pageContexts = collect($pages)->mapWithKeys(fn (array $page) => [
            (int) ($page['page'] ?? 1) => [
                'course_map' => (bool) preg_match('/^(?:Course\s+Unit\s+Map|Unit\s+Sequence\s+at\s+a\s+Glance)\s*$/miu', (string) ($page['text'] ?? '')),
                'items' => collect($page['items'] ?? [])->map(fn (array $item) => [
                    'text' => $this->clean((string) ($item['text'] ?? '')),
                    'x' => (float) ($item['x'] ?? 0), 'y' => (float) ($item['y'] ?? 0),
                ])->filter(fn (array $item) => $item['text'] !== '')->values()->all(),
            ],
        ])->all();

        $candidates = [];
        foreach ($entries as $index => $entry) {
            if (! preg_match(self::HEADING, $entry['text'], $match)) continue;
            $context = $pageContexts[$entry['page']] ?? ['course_map' => false, 'items' => []];
            $title = trim($match[3]);
            $positioned = collect($context['items'])->first(fn (array $item) => $this->comparison($item['text']) === $this->comparison($entry['text']));
            $next = $entries[$index + 1]['text'] ?? null;
            $proseFollows = is_string($next) && (bool) preg_match('/^(?:I\s+can|students?\s+(?:will|can)|learners?\s+(?:will|can))\b/iu', $next);
            $sentenceLike = (bool) preg_match('/\b(?:I\s+can|students?\s+(?:will|can)|learners?\s+(?:will|can)|objectives?|will\s+learn)\b/iu', $title)
                || $proseFollows || mb_strlen($title) > 120 || str_word_count($title) > 18;
            $sectionFollows = is_string($next) && $this->isSectionHeading($next);
            $courseMap = (bool) $context['course_map'] && ! $sectionFollows;
            $score = ($courseMap ? -20 : 5)
                + ($match[2] === ':' ? 2 : 4)
                + ($positioned ? 2 : 0)
                + ($positioned && $positioned['x'] <= 90 && $positioned['y'] >= 650 ? 2 : 0)
                + ($sectionFollows ? 3 : 0)
                - ($sentenceLike ? 20 : 0);
            $candidates[(int) $match[1]][] = [
                'index' => $index, 'number' => (int) $match[1], 'title' => $title,
                'heading' => $entry['text'], 'page' => $entry['page'], 'separator' => $match[2],
                'score' => $score, 'course_map' => $courseMap, 'sentence_like' => $sentenceLike,
                'positioned' => (bool) $positioned, 'section_follows' => $sectionFollows, 'prose_follows' => $proseFollows,
            ];
        }

        $units = [];
        $ambiguities = [];
        foreach ($candidates as $number => $numberCandidates) {
            $eligible = collect($numberCandidates)->filter(fn (array $candidate) => $candidate['score'] >= 7
                && ! $candidate['course_map'] && ! $candidate['sentence_like'])->values();
            if ($eligible->isEmpty()) continue;
            $ranked = $eligible->sort(function (array $left, array $right): int {
                return [$right['score'], mb_strlen($left['title']), $left['index']]
                    <=> [$left['score'], mb_strlen($right['title']), $right['index']];
            })->values();
            $best = $ranked->first();
            $tied = $ranked->filter(fn (array $candidate) => $candidate['score'] === $best['score']
                && mb_strlen($candidate['title']) === mb_strlen($best['title']))->values();
            if ($tied->map(fn (array $candidate) => $this->comparison($candidate['heading']))->unique()->count() > 1) {
                $ambiguities[] = ['number' => $number, 'candidates' => $tied->pluck('heading')->all()];
                continue;
            }
            $units[] = $best;
        }

        usort($units, fn (array $left, array $right) => $left['number'] <=> $right['number']);
        if (count($units) >= 2) {
            $numbers = array_column($units, 'number');
            $missing = array_values(array_diff(range(1, max($numbers)), $numbers));
            if ($missing !== []) {
                $ambiguities[] = [
                    'type' => 'missing_unit_numbers',
                    'numbers' => $missing,
                    'message' => 'Explicit numbered unit headings are incomplete; review the source extraction before importing.',
                ];
            }
        }

        return ['entries' => $entries, 'units' => $units, 'ambiguities' => $ambiguities];
    }

    /** @return array<int, array{text: string, page: int}> */
    private function entries(array $pages): array
    {
        $entries = [];
        $documentTitle = $this->clean((string) (preg_split('/\R/u', (string) ($pages[0]['text'] ?? ''))[0] ?? ''));
        $skipRepeatedTitle = ! preg_match(self::HEADING, $documentTitle);
        foreach ($pages as $page) {
            $pageLines = preg_split('/\R/u', (string) ($page['text'] ?? '')) ?: [];
            for ($index = 0; $index < count($pageLines); $index++) {
                $line = $pageLines[$index];
                $line = $this->clean($line);
                $next = $this->clean((string) ($pageLines[$index + 1] ?? ''));
                if (preg_match(self::HEADING, $line, $match) && $this->isHeadingContinuation($match[3], $next)) {
                    $line = $this->clean($line.' '.$next);
                    $index++;
                }
                if ($line !== '' && (! $skipRepeatedTitle || $line !== $documentTitle)) {
                    $entries[] = ['text' => $line, 'page' => (int) ($page['page'] ?? 1)];
                }
            }
        }
        return $entries;
    }

    private function isSectionHeading(string $line): bool
    {
        return (bool) preg_match('/^(?:suggested\s+)?duration\b|^instruction\s+window\b|^primary\s+teks\b|^(?:anchor|growing(?:\s+unit)?|unit)\s+project\b|^big\s+idea\b|^key\s+content\b|^social\s+studies\s+skills\s+to\s+practice\b|^(?:learning\s+(?:goals|objectives)|objectives)\b|^(?:(?:main|programming)\s+skills|skills)\b|^vocabulary\b|^useful\s+phrases\b|^(?:project\s+milestones|milestones|builds)\b|^challenge\s+(?:missions|activities|extensions)\b|^(?:end-of-unit\s+evidence|evidence\s+of\s+learning|assessment|skill\s+demonstration)\b/iu', $line);
    }

    private function isHeadingContinuation(string $title, string $next): bool
    {
        if ($next === '' || mb_strlen($next) > 120 || $this->isSectionHeading($next)
            || preg_match(self::HEADING, $next)
            || preg_match('/^(?:Scheduling Rules|No-Instruction Dates\s*\/\s*Breaks|Course Unit Map|Detailed Unit Guidance|Implementation Notes for the Learning App)\b/iu', $next)) {
            return false;
        }

        return (bool) preg_match('/^(?:and|or|of|for|with|through|to|in|on)\b/iu', $next)
            || (bool) preg_match('/[-\x{2013}\x{2014}:,]\s*$/u', $title);
    }

    private function comparison(string $value): string
    {
        return mb_strtolower(preg_replace('/\s*[-\x{2013}\x{2014}:]\s*/u', '-', $this->clean($value)) ?? $value);
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
