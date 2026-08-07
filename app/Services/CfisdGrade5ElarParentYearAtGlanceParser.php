<?php

namespace App\Services;

use App\Contracts\CurriculumOutlineParser;
use App\Data\CurriculumParserApplicability;
use App\Data\CurriculumParserResult;
use App\Data\CurriculumProposalData;
use App\Models\AcademicSource;
use App\Models\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CfisdGrade5ElarParentYearAtGlanceParser implements CurriculumOutlineParser
{
    public const KEY = 'cfisd-grade5-elar-yag-parent';
    public const VERSION = 'cfisd-grade5-elar-yag-parent-v3';

    private const MONTHS = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5,
        'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10,
        'november' => 11, 'december' => 12,
    ];

    public function supports(array $pages, AcademicSource $source): bool
    {
        return $this->recognitionScore($pages, $source) > 0;
    }

    public function recognitionScore(array $pages, AcademicSource $source): float
    {
        if (! $this->sourceContextMatches($source) || count($pages) !== 4) return 0.0;
        $allText = mb_strtolower(collect($pages)->pluck('text')->implode("\n"));
        if (str_contains($allText, 'teacher edition')
            || ! str_contains($allText, 'grade 5 reading language arts year at a glance')
            || ! str_contains($allText, '2026-2027')
            || ! str_contains($allText, 'updated 06/30/2026')) return 0.0;

        foreach (array_values($pages) as $index => $page) {
            $text = mb_strtolower((string) ($page['text'] ?? ''));
            $items = collect($page['items'] ?? []);
            $period = $index + 1;
            if (! str_contains($text, "{$this->ordinal($period)} grading period")
                || ! $items->contains(fn (array $item) => str_contains(mb_strtolower((string) ($item['text'] ?? '')), 'grade 5 reading language arts year at a glance'))
                || ! $items->contains(fn (array $item) => preg_match("/^{$period}(?:st|nd|rd|th) Grading Period$/iu", trim((string) ($item['text'] ?? ''))))
                || $items->filter(fn (array $item) => preg_match('/^Unit\s+\d+$/iu', trim((string) ($item['text'] ?? ''))))->count() < 3
                || $items->filter(fn (array $item) => preg_match('/^\d{1,2}-\d{1,2}$/', trim((string) ($item['text'] ?? ''))))->count() < 6
                || ! $items->contains(fn (array $item) => trim((string) ($item['text'] ?? '')) === 'READING SKILLS')
                || ! $items->contains(fn (array $item) => trim((string) ($item['text'] ?? '')) === 'WRITING SKILLS')) return 0.0;
        }

        return .995;
    }

    public function applicability(): CurriculumParserApplicability
    {
        return new CurriculumParserApplicability(
            providerCodes: ['CFISD', 'Cypress-Fairbanks Independent School District'],
            subjectCodes: ['ELAR'],
            gradeCodes: ['G5', '5', 'Grade 5'],
            sourceCategories: ['curriculum', 'scope_and_sequence'],
            mimeTypes: ['application/pdf'],
            extensions: ['pdf'],
            documentFamily: 'Parent Year at a Glance',
            priority: 100,
        );
    }

    public function parse(array $pages, AcademicSource $source): CurriculumParserResult
    {
        if (! $this->supports($pages, $source)) {
            throw new \RuntimeException('The PDF does not match the supported CFISD Grade 5 ELAR parent layout.');
        }

        $periods = [];
        $units = [];
        $assessments = [];
        foreach ($pages as $page) {
            $pageNumber = (int) ($page['page'] ?? 1);
            $items = collect($page['items'] ?? [])->map(fn (array $item) => [
                ...$item, 'text' => $this->clean((string) ($item['text'] ?? '')),
            ])->filter(fn (array $item) => $item['text'] !== '')->values();
            preg_match('/([1-4])(?:st|nd|rd|th)\s+Grading Period/iu', (string) ($page['text'] ?? ''), $periodMatch);
            $periodSequence = (int) ($periodMatch[1] ?? $pageNumber);
            $weeks = $this->weeks($items, $source);
            $periods[$periodSequence] = [
                'name' => $this->ordinal($periodSequence).' Grading Period',
                'start' => collect($weeks)->pluck('start')->filter()->min(),
                'end' => collect($weeks)->pluck('end')->filter()->max(),
                'page' => $pageNumber,
                'semester' => $periodSequence <= 2 ? '1st Semester' : '2nd Semester',
                'raw' => $this->evidence($items, fn ($item) => (float) $item['y'] >= 390 && (float) $item['y'] <= 435),
            ];

            $headings = $items->filter(fn (array $item) => preg_match('/^Unit\s+(\d+)$/iu', $item['text']))
                ->sortBy('x')->values();
            foreach ($headings as $index => $heading) {
                preg_match('/\d+/', $heading['text'], $numberMatch);
                $number = (int) $numberMatch[0];
                $left = $index === 0 ? -INF : (((float) $headings[$index - 1]['x'] + (float) $heading['x']) / 2);
                $right = $index === $headings->count() - 1 ? INF : (((float) $heading['x'] + (float) $headings[$index + 1]['x']) / 2);
                $unitWeeks = collect($weeks)->filter(fn (array $week) => $week['x'] >= $left && $week['x'] < $right);
                $appearance = [
                    'page' => $pageNumber, 'period' => $periodSequence, 'left' => $left, 'right' => $right,
                    'start' => $unitWeeks->pluck('start')->filter()->min(), 'end' => $unitWeeks->pluck('end')->filter()->max(),
                    'components' => $this->components($items, $left, $right, $number, $pageNumber, $periodSequence),
                ];
                if (! isset($units[$number])) {
                    $units[$number] = ['period' => $periodSequence, 'start' => $appearance['start'], 'end' => $appearance['end'], 'appearances' => []];
                }
                $units[$number]['start'] = collect([$units[$number]['start'], $appearance['start']])->filter()->min();
                $units[$number]['end'] = collect([$units[$number]['end'], $appearance['end']])->filter()->max();
                $units[$number]['appearances'][] = $appearance;
            }
            $assessments = [...$assessments, ...$this->assessments((string) ($page['text'] ?? ''), $periodSequence, $pageNumber, $source)];
        }

        ksort($periods); ksort($units);
        $proposals = [];
        foreach ($periods as $sequence => $period) {
            $proposals[] = new CurriculumProposalData(
                "period:{$sequence}", null, 'period', $sequence, $period['name'], $period['start'], $period['end'],
                unitType: null, reportingPeriod: $period['name'], sourcePage: $period['page'], rawText: $period['raw'],
                parserNote: "Mapped from the positioned {$period['semester']} grading-period and week grid.", confidence: .99,
                parserMetadata: ['semester' => $period['semester']],
            );
        }
        foreach ($units as $number => $unit) {
            $periodName = $periods[$unit['period']]['name'];
            $unitKey = "unit:{$number}";
            $pagesUsed = collect($unit['appearances'])->pluck('page')->unique()->values()->all();
            $proposals[] = new CurriculumProposalData(
                $unitKey, "period:{$unit['period']}", 'unit', $number, "Unit {$number}", $unit['start'], $unit['end'],
                unitType: 'instructional', reportingPeriod: $periodName, sourcePage: min($pagesUsed),
                rawText: 'Unit '.$number.'; positioned on page'.(count($pagesUsed) > 1 ? 's ' : ' ').implode(', ', $pagesUsed),
                parserNote: count($pagesUsed) > 1
                    ? 'This unit crosses a grading-period page boundary; its positioned appearances were merged into one unit.'
                    : 'Mapped from the positioned unit and week/date columns.',
                confidence: count($pagesUsed) > 1 ? .9 : .98,
                parserMetadata: ['source_pages' => $pagesUsed],
                summary: $this->unitSummary($unit['appearances']),
            );
            $this->appendComponents($proposals, $unitKey, $number, $unit['appearances']);
        }
        foreach ($assessments as $assessment) {
            $proposals[] = new CurriculumProposalData(
                'assessment:'.Str::slug($assessment['name']), "period:{$assessment['period']}", 'assessment',
                100 + $assessment['sequence'], $assessment['name'], $assessment['start'], $assessment['end'],
                unitType: 'assessment', reportingPeriod: $periods[$assessment['period']]['name'],
                sourcePage: $assessment['page'], rawText: $assessment['raw'],
                parserNote: 'Mapped from the positioned testing row; wording and printed date window are preserved.', confidence: .99,
            );
        }

        return new CurriculumParserResult(
            'Grade 5 Reading Language Arts Year at a Glance 2026-2027',
            '2026-06-30', 'Grade 5', 'English Language Arts and Reading', '2026-2027', $proposals,
        );
    }

    public function key(): string { return self::KEY; }
    public function version(): string { return self::VERSION; }
    public function extractionMethod(): string { return 'pdf_positioned_text'; }

    private function appendComponents(array &$proposals, string $unitKey, int $unitNumber, array $appearances): void
    {
        $roots = [];
        $sequences = [];
        $siblings = [];
        foreach ($appearances as $appearance) {
            foreach ($appearance['components'] as $component) {
                $rootIdentity = $component['name'].'|'.$component['type'];
                $rootAlreadyExists = isset($roots[$rootIdentity]);
                if (! $rootAlreadyExists) {
                    $rootKey = "component:{$unitNumber}:root:".Str::slug($rootIdentity);
                    $roots[$rootIdentity] = $rootKey;
                    $proposals[] = new CurriculumProposalData(
                        $rootKey, $unitKey, 'component', count($roots), $component['name'],
                        plannedStartDate: $appearance['start'], plannedEndDate: $appearance['end'],
                        componentType: $component['type'], description: $component['description'],
                        sourcePage: $appearance['page'], rawText: $component['raw'], parserNote: $component['note'],
                        confidence: $component['confidence'], parserMetadata: $component['metadata'],
                    );
                }
                $children = $component['children'];
                if ($rootAlreadyExists && $children === [] && ($component['description'] || $component['raw'])) {
                    $children[] = [
                        'type' => $component['type'], 'name' => $component['name'].' continuation',
                        'description' => $component['description'], 'raw' => $component['raw'],
                        'confidence' => $component['confidence'], 'metadata' => $component['metadata'],
                        'note' => 'This continuation is preserved from another positioned page appearance of the same unit.',
                    ];
                }
                $this->appendChildComponents($proposals, $children, $roots[$rootIdentity], $unitNumber, $appearance, $sequences, $siblings);
            }
        }
    }

    private function appendChildComponents(array &$proposals, array $children, string $parentKey, int $unitNumber, array $appearance, array &$sequences, array &$siblings): void
    {
        foreach ($children as $child) {
            if ($this->isCrossReference($child['description'] ?? $child['name'])) continue;
            $identity = mb_strtolower($child['type'].'|'.preg_replace('/[^\pL\pN]+/u', ' ', $child['name']));
            if (isset($siblings[$parentKey][$identity])) continue;
            $siblings[$parentKey][$identity] = true;
            $sequence = ($sequences[$parentKey] ?? 0) + 1;
            $sequences[$parentKey] = $sequence;
            $key = "component:{$unitNumber}:{$appearance['page']}:".Str::slug($parentKey.'-'.$child['name']).":{$sequence}";
            $proposals[] = new CurriculumProposalData(
                $key, $parentKey, 'component', $sequence, $child['name'],
                plannedStartDate: $appearance['start'], plannedEndDate: $appearance['end'],
                componentType: $child['type'], description: $child['description'],
                sourcePage: $appearance['page'], rawText: $child['raw'], parserNote: $child['note'],
                confidence: $child['confidence'], parserMetadata: $child['metadata'],
            );
            $this->appendChildComponents($proposals, $child['children'] ?? [], $key, $unitNumber, $appearance, $sequences, $siblings);
        }
    }

    private function components(Collection $items, float $left, float $right, int $unitNumber, int $page, int $period): array
    {
        $baseMetadata = ['unit_number' => $unitNumber, 'grading_period' => $period, 'source_page' => $page];
        $components = [];
        $readingModuleEvidence = $this->cell($items, 292, 327, $left, $right, ['Focus TEKS']);
        $readingModule = $this->normalizeModuleCell($readingModuleEvidence);
        $readingSkillsEvidence = $this->cell($items, 170, 288, $left, $right);
        $readingSkills = $this->normalizeDetailCell($readingSkillsEvidence);
        $readingChildren = [];
        if ($readingModule && ! $this->isCrossReference($readingModule)) {
            foreach ($this->moduleNames($readingModule) as $module) $readingChildren[] = $this->atomicChild('module', $module, $readingModuleEvidence, $baseMetadata);
        }
        [$readingGenre, $readingSkillText] = $this->genreAndSkills($readingSkills);
        foreach ($this->listChildren('genre', $readingGenre, $baseMetadata, sourceRaw: $readingSkillsEvidence) as $child) $readingChildren[] = $child;
        foreach ($this->listChildren('skill', $readingSkillText, $baseMetadata, sourceRaw: $readingSkillsEvidence) as $child) $readingChildren[] = $child;
        if ($readingChildren) $components[] = $this->root('strand', 'Reading', $readingChildren, $baseMetadata);

        $hasFocusTeks = $items->contains(fn (array $item) => (float) $item['x'] >= $left && (float) $item['x'] < $right
            && strcasecmp($item['text'], 'Focus TEKS') === 0);

        $writingModuleEvidence = $this->cell($items, 130, 160, $left, $right);
        $writingModule = $this->normalizeModuleCell($writingModuleEvidence);
        $writingSkillsEvidence = $this->cell($items, 15, 129, $left, $right);
        $writingSkills = $this->normalizeDetailCell($writingSkillsEvidence);
        $writingChildren = [];
        if ($writingModule && ! $this->isCrossReference($writingModule)) {
            foreach ($this->moduleNames($writingModule) as $module) $writingChildren[] = $this->atomicChild('module', $module, $writingModuleEvidence, $baseMetadata);
        }
        [$writingGenre, $writingSkillText, $revisingText] = $this->genreSkillsAndRevising($writingSkills);
        foreach ($this->listChildren('genre', $writingGenre, $baseMetadata, sourceRaw: $writingSkillsEvidence) as $child) $writingChildren[] = $child;
        foreach ($this->listChildren('skill', $writingSkillText, $baseMetadata, sourceRaw: $writingSkillsEvidence) as $child) $writingChildren[] = $child;
        foreach ($this->listChildren('revising', $revisingText, $baseMetadata, sourceRaw: $writingSkillsEvidence) as $child) $writingChildren[] = $child;
        $ecrEvidence = $this->cell($items, -78, -25, $left, $right);
        $ecr = $this->normalizeDetailCell($ecrEvidence);
        if ($ecr && ! $this->isCrossReference($ecr)) {
            $ecrText = trim((string) preg_replace('/^(?:ECR|Extended Constructed Response)(?:\s+support)?\s*:\s*/iu', '', $ecr));
            $writingChildren[] = $this->root('assessment_support', 'Extended Constructed Response support', $this->listChildren('skill', $ecrText, $baseMetadata, sourceRaw: $ecrEvidence), $baseMetadata, raw: $ecrEvidence);
        }
        if ($writingChildren) $components[] = $this->root('strand', 'Writing', $writingChildren, $baseMetadata);

        foreach ([
            ['conventions', 'Editing and Grammar', -134, -88],
            ['foundational_skill', 'Foundational Skills', -170, -138],
            ['handwriting', 'Handwriting Without Tears', -204, -176],
            ['integrated_subject', 'Integrated Social Studies', -238, -208],
        ] as [$type, $name, $minY, $maxY]) {
            $evidence = $this->cell($items, $minY, $maxY, $left, $right);
            $description = $this->normalizeDetailCell($evidence);
            if ($type === 'handwriting') $description = $this->clean(preg_replace('/\b(?:HANDWRITING|WITHOUT TEARS)\b/u', '', $description) ?? $description);
            if ($type === 'integrated_subject') $description = $this->clean(preg_replace('/\b(?:INTEGRATED|SOCIAL STUDIES)\b/u', '', $description) ?? $description);
            if ($type === 'foundational_skill') $description = $this->clean(preg_replace('/\b(?:FOUNDATIONAL|SKILLS)\b/u', '', $description) ?? $description);
            if ($description && ! $this->isCrossReference($description)) {
                $children = in_array($type, ['conventions', 'foundational_skill'], true)
                    ? $this->listChildren($type === 'foundational_skill' ? 'foundational_skill' : 'skill', $description, $baseMetadata, sourceRaw: $evidence)
                    : $this->listChildren($type, $description, $baseMetadata, true, true, $evidence);
                $components[] = $this->root($type, $name, $children, $baseMetadata, raw: $evidence);
            }
        }

        if ($hasFocusTeks) {
            $components[] = $this->root('resource', 'Focus TEKS Evidence', [], $baseMetadata,
                'The document shows a Focus TEKS heading but no reliably extractable codes in this cell.', .65,
                'Focus TEKS', 'Evidence retained without inventing standard codes.');
        }

        return $components;
    }

    private function root(string $type, string $name, array $children, array $metadata, ?string $description = null, float $confidence = .94, ?string $raw = null, ?string $note = null): array
    {
        $raw ??= $description ?: collect($children)->pluck('raw')->filter()->implode(' | ');
        return compact('type', 'name', 'children', 'metadata', 'description', 'confidence', 'raw') + [
            'note' => $note ?? 'Associated with the unit from positioned row and column boundaries.',
        ];
    }

    private function atomicChild(string $type, string $name, string $raw, array $metadata, float $confidence = .94, ?string $note = null): array
    {
        return [
            'type' => $type, 'name' => $name, 'description' => null, 'raw' => $raw, 'children' => [],
            'confidence' => $confidence, 'metadata' => [...$metadata, 'source_wording' => $raw],
            'note' => $note ?? 'Separated from source wording within the positioned unit cell.',
        ];
    }

    private function genreAndSkills(string $text): array
    {
        if ($text === '' || $this->isCrossReference($text)) return [null, null];
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        preg_match('/Genre\s*:\s*(.*?)(?=\bSkills\s*:|$)/iu', $text, $genre);
        preg_match('/Skills\s*:\s*(.*)$/iu', $text, $skills);
        if (! $genre && ! $skills) return [null, $text];

        return [trim($genre[1] ?? '') ?: null, trim($skills[1] ?? '') ?: null];
    }

    private function genreSkillsAndRevising(string $text): array
    {
        if ($text === '' || $this->isCrossReference($text)) return [null, null, null];
        $text = $this->clean($text);
        preg_match('/Genre\s*:\s*(.*?)(?=\bSkills\s*:|\bRevising\s*:|$)/iu', $text, $genre);
        preg_match('/Skills\s*:\s*(.*?)(?=\bRevising\s*:|$)/iu', $text, $skills);
        preg_match('/Revising\s*:\s*(.*)$/iu', $text, $revising);
        if (! $genre && ! $skills && ! $revising) return [null, $text, null];
        return [trim($genre[1] ?? '') ?: null, trim($skills[1] ?? '') ?: null, trim($revising[1] ?? '') ?: null];
    }

    private function moduleNames(string $text): array
    {
        $parts = preg_split('/\s+(?=(?:Launching Literacy|HMH Modules?\b|District (?:Modules?|Units?)\b|STAAR Review\b|Informational ECR\b))/iu', $this->clean($text)) ?: [];
        return $this->uniqueNames($parts);
    }

    private function listChildren(string $type, ?string $text, array $metadata, bool $commaIsBoundary = true, bool $strongOnly = false, ?string $sourceRaw = null): array
    {
        if (! $text) return [];
        [$parts, $ambiguous] = $this->splitList($text, $commaIsBoundary, $strongOnly);
        return array_map(fn (string $name) => $this->atomicChild(
            $type, $name, $sourceRaw ?? $text, $metadata, $ambiguous ? .74 : .94,
            $ambiguous ? 'Comma boundaries are ambiguous; review this source-grounded component split.' : null,
        ), $this->uniqueNames($parts));
    }

    private function splitList(string $text, bool $commaIsBoundary, bool $strongOnly): array
    {
        $text = $this->clean((string) preg_replace('/^(?:Skills|Revising|Genre)\s*:\s*/iu', '', $text));
        $semicolons = $this->splitOutsideParentheses($text, ';');
        if (count($semicolons) > 1) return [$semicolons, false];
        if ($strongOnly) return [[$text], true];
        if (! $commaIsBoundary) {
            $commas = $commaIsBoundary ? $this->splitOutsideParentheses($text, ',') : [$text];
            return [$commas, count($commas) > 1];
        }
        $commas = $this->splitOutsideParentheses($text, ',');
        return [$commas, count($commas) > 1];
    }

    private function splitOutsideParentheses(string $text, string $delimiter): array
    {
        $parts = []; $current = ''; $depth = 0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($character === '(') $depth++;
            if ($character === ')') $depth = max(0, $depth - 1);
            if ($character === $delimiter && $depth === 0) { $parts[] = $current; $current = ''; continue; }
            $current .= $character;
        }
        $parts[] = $current;
        return array_values(array_filter(array_map(fn ($part) => trim($part, " \t\n\r\0\x0B.;"), $parts)));
    }

    private function uniqueNames(array $names): array
    {
        $seen = [];
        return array_values(array_filter(array_map(fn ($name) => $this->clean((string) $name), $names), function (string $name) use (&$seen): bool {
            if ($name === '') return false;
            $key = mb_strtolower(preg_replace('/[^\pL\pN]+/u', '', $name) ?? $name);
            if (isset($seen[$key])) return false;
            return $seen[$key] = true;
        }));
    }

    private function normalizeModuleCell(string $text): string
    {
        $text = preg_replace('/\bLaunching\s+(HMH Modules?\s+\d+\s*:\s*)Literacy\s+MODULE\s+/u', 'Launching Literacy $1', $text) ?? $text;
        $text = preg_replace('/\b(?:READING|WRITING|FOCUS TEKS)\b/u', '', $text) ?? $text;
        $text = preg_replace('/\bMODULE\b/u', '', $text) ?? $text;
        return $this->clean($text);
    }

    private function normalizeDetailCell(string $text): string
    {
        $text = preg_replace('/\b(?:Launching|Literacy|Lessons)\b/u', '', $text) ?? $text;
        $text = preg_replace('/\s+Skills\s*$/u', '', $text) ?? $text;
        return $this->clean($text);
    }

    private function unitSummary(array $appearances): ?string
    {
        $parts = [];
        foreach (['Reading', 'Writing'] as $strand) {
            $modules = [];
            $genres = [];
            foreach ($appearances as $appearance) {
                $root = collect($appearance['components'])->firstWhere('name', $strand);
                foreach (($root['children'] ?? []) as $child) {
                    if ($child['type'] === 'module') $modules[] = $child['name'];
                    if ($child['type'] === 'genre') $genres[] = $child['name'];
                }
            }
            $modules = $this->uniqueNames($modules);
            $genres = $this->uniqueNames($genres);
            if ($modules) $parts[] = $strand.': '.implode(' and ', $modules);
            elseif ($genres) $parts[] = $strand.' genres: '.implode(', ', array_slice($genres, 0, 3));
        }
        return $parts ? implode(' · ', $parts) : null;
    }

    private function cell(Collection $items, float $minY, float $maxY, float $left, float $right, array $exclude = []): string
    {
        $parts = $items->filter(fn (array $item) => (float) $item['y'] >= $minY && (float) $item['y'] <= $maxY
            && (float) $item['x'] >= $left && (float) $item['x'] < $right
            && ! in_array($item['text'], $exclude, true)
            && ! in_array($item['text'], ['READING SKILLS', 'WRITING SKILLS', 'EDITING (PoP)', 'FOUNDATIONAL SKILLS', 'HANDWRITING WITHOUT TEARS', 'INTEGRATED SOCIAL STUDIES'], true))
            ->sort(fn (array $a, array $b) => abs((float) $a['y'] - (float) $b['y']) < .5
                ? ((float) $a['x'] <=> (float) $b['x']) : ((float) $b['y'] <=> (float) $a['y']))
            ->pluck('text')->implode(' ');

        return $this->clean($parts);
    }

    private function weeks(Collection $items, AcademicSource $source): array
    {
        $monthItems = $items->filter(fn (array $item) => isset(self::MONTHS[mb_strtolower($item['text'])]))->sortBy('x')->values();
        $month = self::MONTHS[mb_strtolower((string) ($monthItems->first()['text'] ?? 'august'))];
        $weeks = [];
        $previousStartDay = null;
        $previousCrossedMonth = false;
        foreach ($items->filter(fn (array $item) => preg_match('/^(\d{1,2})-(\d{1,2})$/', $item['text']))->sortBy('x') as $item) {
            preg_match('/^(\d{1,2})-(\d{1,2})$/', $item['text'], $match);
            $startDay = (int) $match[1]; $endDay = (int) $match[2];
            if ($previousStartDay !== null && $startDay < $previousStartDay && ! $previousCrossedMonth) $month++;
            if ($month > 12) $month = 1;
            $startMonth = $month; $endMonth = $endDay < $startDay ? ($month === 12 ? 1 : $month + 1) : $month;
            $startYear = $this->yearForMonth($source, $startMonth);
            $endYear = $this->yearForMonth($source, $endMonth);
            $weeks[] = [
                'x' => (float) $item['x'], 'raw' => $item['text'],
                'start' => CarbonImmutable::createSafe($startYear, $startMonth, $startDay)->format('Y-m-d'),
                'end' => CarbonImmutable::createSafe($endYear, $endMonth, $endDay)->format('Y-m-d'),
            ];
            if ($endMonth !== $month) $month = $endMonth;
            $previousStartDay = $startDay;
            $previousCrossedMonth = $endDay < $startDay;
        }
        return $weeks;
    }

    private function assessments(string $text, int $period, int $page, AcademicSource $source): array
    {
        $definitions = [
            [1, 'BOY MAP Growth'], [2, 'DPM 1'], [3, 'DPM 2'], [4, 'DPM 3'],
            [5, 'RLA STAAR'], [6, 'EOY MAP Growth'],
        ];
        $results = [];
        foreach ($definitions as [$sequence, $name]) {
            $pattern = '/'.preg_quote($name, '/').'.{0,40}?\(\s*(\d{1,2})\/(\d{1,2})\s*-\s*(\d{1,2})\/(\d{1,2})\s*\)/isu';
            if (! preg_match($pattern, $text, $match)) continue;
            $start = CarbonImmutable::createSafe($this->yearForMonth($source, (int) $match[1]), (int) $match[1], (int) $match[2])->format('Y-m-d');
            $end = CarbonImmutable::createSafe($this->yearForMonth($source, (int) $match[3]), (int) $match[3], (int) $match[4])->format('Y-m-d');
            $results[] = compact('sequence', 'name', 'period', 'page', 'start', 'end') + ['raw' => $this->clean($match[0])];
        }
        return $results;
    }

    private function yearForMonth(AcademicSource $source, int $month): int
    {
        $year = $source->schoolYear;
        if ($year) return $month >= $year->start_date->month ? $year->start_date->year : $year->end_date->year;
        return $month >= 7 ? 2026 : 2027;
    }

    private function sourceContextMatches(AcademicSource $source): bool
    {
        $source->loadMissing(['educationProvider', 'gradeLevel', 'links']);
        $subjectId = $source->links->firstWhere('link_type', 'subject')?->link_id;
        if (! $subjectId || Subject::query()->whereKey($subjectId)->value('code') !== 'ELAR') return false;
        if (! in_array($source->gradeLevel?->code, ['G5', '5'], true) && $source->gradeLevel?->name !== 'Grade 5') return false;
        if ($source->educationProvider && $source->educationProvider->short_name !== 'CFISD'
            && $source->educationProvider->name !== 'Cypress-Fairbanks Independent School District') return false;
        return in_array($source->source_category, ['curriculum', 'scope_and_sequence'], true);
    }

    private function evidence(Collection $items, callable $filter): string
    {
        return $this->clean($items->filter($filter)->sortByDesc('y')->pluck('text')->implode(' '));
    }

    private function isCrossReference(string $text): bool
    {
        return (bool) preg_match('/^See\s+\d+(?:st|nd|rd|th)\s+Grading\s+Period$/iu', $this->clean($text));
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function ordinal(int $number): string
    {
        return $number.match ($number) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
    }
}
