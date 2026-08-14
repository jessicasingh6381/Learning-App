<?php

namespace App\Services;

use App\Contracts\CurriculumOutlineParser;
use App\Data\CurriculumParserApplicability;
use App\Data\CurriculumParserResult;
use App\Data\CurriculumProposalData;
use App\Models\AcademicSource;
use App\Models\Subject;

final class StructuredCustomCurriculumParser implements CurriculumOutlineParser
{
    public const KEY = 'custom-homeschool-curriculum';
    public const VERSION = '1.2.0';
    public const FAMILY = 'custom-homeschool-curriculum';

    private const SECTION_PATTERNS = [
        'duration' => '/^(?:suggested\s+)?duration\s*:?\s*(.*)$/iu',
        'anchor_project' => '/^(?:anchor|growing(?:\s+unit)?)\s+project\s*:?\s*(.*)$/iu',
        'unit_project' => '/^unit\s+project\s*:?\s*(.*)$/iu',
        'big_idea' => '/^big\s+idea\s*:?\s*(.*)$/iu',
        'instruction_window' => '/^instruction\s+window\s*:?\s*(.*)$/iu',
        'primary_teks' => '/^primary\s+teks\s*:?\s*(.*)$/iu',
        'key_content' => '/^key\s+content\s*:?\s*(.*)$/iu',
        'social_studies_skills' => '/^social\s+studies\s+skills\s+to\s+practice\s*:?\s*(.*)$/iu',
        'objectives' => '/^(?:learning\s+(?:goals|objectives)|objectives)\s*:?\s*(.*)$/iu',
        'skills' => '/^(?:(?:main|programming)\s+skills|skills)\s*:?\s*(.*)$/iu',
        'vocabulary' => '/^(?:core\s+)?vocabulary\s*:?\s*(.*)$/iu',
        'useful_phrases' => '/^useful\s+phrases\s*:?\s*(.*)$/iu',
        'milestones' => '/^(?:project\s+milestones|milestones|builds)\s*:?\s*(.*)$/iu',
        'challenges' => '/^(?:challenge\s+(?:missions|activities|extensions))(?:\s*\([^)]*\))?\s*:?\s*(.*)$/iu',
        'evidence' => '/^(?:evidence\s+of\s+learning|assessment|skill\s+demonstration)\s*:?\s*(.*)$/iu',
        'end_of_unit_evidence' => '/^end-of-unit\s+evidence\s*:?\s*(.*)$/iu',
        'requirements' => '/^(?:final\s+unit\s+project\s+requirements|final\s+project\s+requirements)\s*:?\s*(.*)$/iu',
    ];

    public function __construct(private StructuredCurriculumUnitHeadingResolver $headingResolver) {}

    public function supports(array $pages, AcademicSource $source): bool
    {
        return $this->recognitionScore($pages, $source) > 0;
    }

    public function recognitionScore(array $pages, AcademicSource $source): float
    {
        $headingResolution = $this->headingResolver->resolve($pages);
        if ($headingResolution['ambiguities'] !== []
            && count($headingResolution['units']) + count($headingResolution['ambiguities']) >= 2) {
            return .72;
        }
        $units = $this->units($pages);
        if (count($units) < 2 || ! $this->sequentialUnitNumbers($units)) return 0.0;

        $concepts = collect($units)->flatMap(fn (array $unit) => array_keys($unit['sections']))->unique();
        $structuredUnits = collect($units)->filter(fn (array $unit) => count(array_intersect(
            array_keys($unit['sections']), ['big_idea', 'objectives', 'skills', 'key_content', 'social_studies_skills', 'anchor_project', 'unit_project', 'milestones', 'evidence', 'end_of_unit_evidence']
        )) >= 2)->count();

        if ($concepts->count() >= 3 && $structuredUnits >= min(3, count($units))) return .985;
        if ($concepts->count() >= 2 && $structuredUnits >= 1) return .72;

        return 0.0;
    }

    public function applicability(): CurriculumParserApplicability
    {
        return new CurriculumParserApplicability(
            providerCodes: [], subjectCodes: [], gradeCodes: [],
            sourceCategories: ['curriculum', 'pacing', 'scope_and_sequence'],
            mimeTypes: ['application/pdf'], extensions: ['pdf'],
            documentFamily: self::FAMILY, priority: 80,
        );
    }

    public function parse(array $pages, AcademicSource $source): CurriculumParserResult
    {
        if ($this->recognitionScore($pages, $source) < .8) {
            throw new \RuntimeException('The custom curriculum structure needs review before outline extraction.');
        }

        $proposals = [];
        foreach ($this->units($pages) as $unitIndex => $unit) {
            $unitKey = 'unit:'.$unit['number'];
            $duration = $this->sectionText($unit['sections']['duration'] ?? null);
            $summary = $this->sectionText($unit['sections']['big_idea'] ?? null);
            $instructionWindow = $this->sectionText($unit['sections']['instruction_window'] ?? null);
            $primaryTeks = $this->sectionText($unit['sections']['primary_teks'] ?? null);
            $standardCodes = $this->standardCodes($primaryTeks);
            $unitMetadata = $this->metadata($unit, [
                'duration_text' => $duration,
                'instruction_window_text' => $instructionWindow,
                'instruction_windows' => $this->instructionWindows($instructionWindow),
                'primary_teks_text' => $primaryTeks,
            ]);
            $proposals[] = new CurriculumProposalData(
                key: $unitKey,
                parentKey: null,
                proposalType: 'unit',
                sequence: $unitIndex + 1,
                name: $this->displayUnitName($unit['heading']),
                unitType: 'instructional',
                standardCodes: $standardCodes,
                sourcePage: $unit['page'],
                rawText: $unit['heading'],
                parserNote: 'Explicit numbered unit heading recognized by the reusable structured custom-curriculum family.',
                confidence: .99,
                parserMetadata: $unitMetadata,
                summary: $summary,
            );

            $componentSequence = 1;
            if ($summary) {
                $proposals[] = $this->component($unitKey.':overview', $unitKey, $componentSequence++, 'overview', 'Big Idea', $summary, $unit, $unit['sections']['big_idea']);
            }

            foreach ([
                'objectives' => ['objective', 'Learning Objectives'],
                'skills' => ['skill', 'Skills'],
                'key_content' => ['concept', 'Key Content'],
                'social_studies_skills' => ['skill', 'Social Studies Skills to Practice'],
                'vocabulary' => ['resource', 'Vocabulary'],
                'useful_phrases' => ['resource', 'Useful Phrases'],
            ] as $sectionKey => [$type, $label]) {
                if ($text = $this->sectionText($unit['sections'][$sectionKey] ?? null)) {
                    $proposals[] = $this->component($unitKey.':'.$sectionKey, $unitKey, $componentSequence++, $type, $label, $text, $unit, $unit['sections'][$sectionKey]);
                }
            }

            $projectSectionKey = isset($unit['sections']['anchor_project']) ? 'anchor_project' : (isset($unit['sections']['unit_project']) ? 'unit_project' : null);
            $projectKey = null;
            if ($projectSectionKey && ($projectName = $this->sectionText($unit['sections'][$projectSectionKey]))) {
                $projectKey = $unitKey.':project';
                $proposals[] = $this->component($projectKey, $unitKey, $componentSequence++, 'project', $projectName, null, $unit, $unit['sections'][$projectSectionKey]);
            }

            $milestones = $this->positionedMilestones($pages, $unit);
            if ($milestones === []) $milestones = $this->bulletItems($unit['sections']['milestones'] ?? null);
            if ($milestones === []) $milestones = $this->buildItems($unit['sections']['milestones'] ?? null);
            foreach ($milestones as $milestoneIndex => $milestone) {
                $proposals[] = new CurriculumProposalData(
                    key: $unitKey.':milestone:'.($milestoneIndex + 1),
                    parentKey: $projectKey ?: $unitKey,
                    proposalType: 'component',
                    sequence: $milestoneIndex + 1,
                    name: $milestone['name'],
                    sourcePage: $milestone['page'] ?? $unit['page'],
                    rawText: $milestone['raw_text'] ?? $milestone['name'],
                    parserNote: 'Project milestone preserved as a distinct child component.',
                    confidence: .96,
                    parserMetadata: ['document_family' => self::FAMILY, ...($milestone['metadata'] ?? [])],
                    componentType: 'project_milestone',
                    description: $milestone['description'] ?? null,
                );
            }

            foreach ([
                'challenges' => ['extension', 'Challenge Missions'],
                'requirements' => ['assessment_support', 'Final Unit Project Requirements'],
                'evidence' => ['assessment_support', 'Evidence of Learning'],
                'end_of_unit_evidence' => ['assessment_support', 'End-of-Unit Evidence'],
            ] as $sectionKey => [$type, $label]) {
                if ($text = $this->sectionText($unit['sections'][$sectionKey] ?? null)) {
                    $proposals[] = $this->component($unitKey.':'.$sectionKey, $unitKey, $componentSequence++, $type, $label, $text, $unit, $unit['sections'][$sectionKey]);
                }
            }

            if ($duration) {
                $proposals[] = $this->component($unitKey.':duration', $unitKey, $componentSequence, 'duration', 'Suggested Duration', $duration, $unit, $unit['sections']['duration']);
            }
        }

        $metadata = [
            'document_family' => self::FAMILY,
            'course_metadata' => $this->courseMetadata($pages, $source),
            'unit_count' => count($this->units($pages)),
            'reporting_periods_supplied' => false,
            'dates_supplied' => false,
        ];

        return new CurriculumParserResult(
            title: $metadata['course_metadata']['title'] ?: $source->title,
            revisionDate: null,
            gradeLabel: $source->gradeLevel?->name,
            subjectLabel: Subject::query()->find($source->links->firstWhere('link_type', 'subject')?->link_id)?->name,
            schoolYearLabel: $source->schoolYear?->name,
            proposals: $proposals,
            diagnostic: 'Structured custom curriculum recognized automatically. Review the detected units and expandable project details before approval.',
            metadata: $metadata,
        );
    }

    public function key(): string { return self::KEY; }
    public function version(): string { return self::VERSION; }
    public function extractionMethod(): string { return 'structured_custom_curriculum'; }

    /** @return array<int, array<string, mixed>> */
    private function units(array $pages): array
    {
        $resolved = $this->headingResolver->resolve($pages);
        if ($resolved['ambiguities'] !== []) return [];
        $entries = $resolved['entries'];
        $starts = $resolved['units'];

        $units = [];
        foreach ($starts as $position => $start) {
            $end = $starts[$position + 1]['index'] ?? count($entries);
            $slice = array_slice($entries, $start['index'] + 1, $end - $start['index'] - 1);
            $sections = [];
            $current = null;
            foreach ($slice as $entry) {
                if (preg_match('/^(?:Instructional Guidance for|Curriculum Import Structure|Scheduling Rules|No-Instruction Dates\s*\/\s*Breaks|Course Unit Map|Detailed Unit Guidance|Implementation Notes for the Learning App)\b/iu', $entry['text'])) break;
                $matched = null;
                foreach (self::SECTION_PATTERNS as $key => $pattern) {
                    if (preg_match($pattern, $entry['text'], $match)) { $matched = [$key, trim($match[1] ?? '')]; break; }
                }
                if ($matched) {
                    [$current, $inline] = $matched;
                    $sections[$current] = ['heading' => $entry['text'], 'page' => $entry['page'], 'lines' => []];
                    if ($inline !== '') $sections[$current]['lines'][] = $inline;
                    continue;
                }
                if ($current) $sections[$current]['lines'][] = $entry['text'];
            }
            $units[] = [
                'number' => $start['number'], 'title' => $start['title'],
                'heading' => $entries[$start['index']]['text'], 'page' => $entries[$start['index']]['page'],
                'end_page' => ($starts[$position + 1] ?? null) ? $entries[$starts[$position + 1]['index']]['page'] - 1 : (int) (collect($pages)->max('page') ?: 1),
                'sections' => $sections,
            ];
        }

        return $units;
    }

    private function sequentialUnitNumbers(array $units): bool
    {
        $numbers = array_column($units, 'number');
        return count($numbers) === count(array_unique($numbers))
            && $numbers === range($numbers[0], $numbers[0] + count($numbers) - 1);
    }

    private function component(string $key, string $parent, int $sequence, string $type, string $name, ?string $description, array $unit, array $section): CurriculumProposalData
    {
        return new CurriculumProposalData(
            key: $key, parentKey: $parent, proposalType: 'component', sequence: $sequence,
            name: $name, sourcePage: $section['page'] ?? $unit['page'], rawText: $this->sectionEvidence($section),
            parserNote: 'Explicit unit section recognized by the reusable structured custom-curriculum family.',
            confidence: .98, parserMetadata: $this->metadata($unit, ['section_heading' => $section['heading'] ?? $name]),
            componentType: $type, description: $description,
        );
    }

    private function metadata(array $unit, array $extra = []): array
    {
        return ['document_family' => self::FAMILY, 'unit_number' => $unit['number'], ...array_filter($extra, fn ($value) => $value !== null && $value !== '')];
    }

    private function sectionText(?array $section): ?string
    {
        if (! $section) return null;
        $lines = collect($section['lines'] ?? [])->map(fn ($line) => $this->cleanContentLine($line))->filter()->values();
        if ($lines->isEmpty()) return null;
        return $lines->implode("\n");
    }

    private function sectionEvidence(array $section): string
    {
        return trim(($section['heading'] ?? '')."\n".$this->sectionText($section));
    }

    /** @return array<int, string> */
    private function instructionWindows(?string $value): array
    {
        if (! $value) return [];
        $withoutNote = trim((string) preg_replace('/\s*\([^)]*instructional\s+days[^)]*\)\s*$/iu', '', $value));

        return collect(preg_split('/\s*;\s*/u', $withoutNote) ?: [])->map(fn ($window) => $this->clean($window))->filter()->values()->all();
    }

    /** @return array<int, string> */
    private function standardCodes(?string $value): array
    {
        if (! $value) return [];
        preg_match_all('/\b\d{1,2}\.\d{1,2}[A-Z]?\b/u', $value, $matches);

        return collect($matches[0] ?? [])->map(fn ($code) => strtoupper($code))->unique()->values()->all();
    }

    private function cleanContentLine(string $line): string
    {
        $line = $this->clean($line);
        return preg_replace('/^[\x{2022}\x{F0B7}\x{00B7}]\s*/u', '• ', $line) ?? $line;
    }

    private function displayUnitName(string $heading): string
    {
        return $heading;
    }

    /** @return array<int, array<string, mixed>> */
    private function positionedMilestones(array $pages, array $unit): array
    {
        $rows = [];
        $active = false;
        foreach ($pages as $page) {
            $pageNumber = (int) ($page['page'] ?? 1);
            if ($pageNumber < $unit['page'] || $pageNumber > $unit['end_page']) continue;
            $items = collect($page['items'] ?? [])->map(fn ($item) => [
                'text' => $this->clean((string) ($item['text'] ?? '')),
                'x' => (float) ($item['x'] ?? 0), 'y' => (float) ($item['y'] ?? 0),
            ])->filter(fn ($item) => $item['text'] !== '')->values();
            $header = $items->first(fn ($item) => preg_match('/^Milestone$/iu', $item['text']));
            $skillHeader = $items->first(fn ($item) => preg_match('/^(?:Programming\s+)?Skill$/iu', $item['text']));
            $additionHeader = $items->first(fn ($item) => preg_match('/^(?:Project\s+Addition|Description)$/iu', $item['text']));
            if (! $header || ! $skillHeader || ! $additionHeader) continue;
            $active = true;
            $firstBoundary = ($header['x'] + $skillHeader['x']) / 2;
            $secondBoundary = ($skillHeader['x'] + $additionHeader['x']) / 2;
            $current = null;
            foreach ($items->filter(fn ($item) => $item['y'] < $header['y'] - 2)->sortByDesc('y')->values() as $item) {
                if ($this->sectionKey($item['text']) || preg_match('/^Unit\s+\d+\b/iu', $item['text'])) break;
                $column = $item['x'] < $firstBoundary ? 'name' : ($item['x'] < $secondBoundary ? 'skill' : 'addition');
                if ($column === 'name') {
                    if ($current) $rows[] = $this->milestoneRow($current, $pageNumber);
                    $current = ['name' => $item['text'], 'skill' => [], 'addition' => []];
                } elseif ($current) {
                    $current[$column][] = $item['text'];
                }
            }
            if ($current) $rows[] = $this->milestoneRow($current, $pageNumber);
        }
        return $active ? $rows : [];
    }

    private function milestoneRow(array $row, int $page): array
    {
        $skill = $this->joinWrapped($row['skill']);
        $addition = $this->joinWrapped($row['addition']);
        return [
            'name' => $row['name'], 'page' => $page,
            'description' => trim(($skill ? "Skill: {$skill}" : '').($skill && $addition ? "\n" : '').($addition ? "Project addition: {$addition}" : '')) ?: null,
            'raw_text' => trim($row['name'].' | '.$skill.' | '.$addition, ' |'),
            'metadata' => array_filter(['skill' => $skill, 'project_addition' => $addition]),
        ];
    }

    private function joinWrapped(array $lines): string
    {
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $lines)) ?? implode(' ', $lines));
    }

    private function bulletItems(?array $section): array
    {
        if (! $section) return [];
        return collect($section['lines'] ?? [])->filter(fn ($line) => preg_match('/^[\x{2022}\x{F0B7}\x{00B7}]/u', $line))->values()
            ->map(fn ($line) => ['name' => preg_replace('/^[\x{2022}\x{F0B7}\x{00B7}]\s*/u', '', $line), 'page' => $section['page'] ?? 1])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function buildItems(?array $section): array
    {
        if (! $section) return [];
        $items = [];
        $current = null;
        foreach ($section['lines'] ?? [] as $line) {
            $line = $this->clean($line);
            if (preg_match('/^(Build\s+\d+\s*[-\x{2013}\x{2014}:]\s*[^\t]+)(?:\t+(.*))?$/iu', $line, $match)) {
                if ($current) $items[] = $current;
                $current = ['name' => trim($match[1]), 'page' => $section['page'] ?? 1, 'description' => trim($match[2] ?? '') ?: null];
            } elseif ($current && ! preg_match('/^(?:Milestone|What\s+Gets\s+Added|Description)\b/iu', $line)) {
                $current['description'] = trim(($current['description'] ? $current['description'].' ' : '').$line);
            }
        }
        if ($current) $items[] = $current;
        return $items;
    }

    private function sectionKey(string $line): ?string
    {
        foreach (self::SECTION_PATTERNS as $key => $pattern) if (preg_match($pattern, $line)) return $key;
        return null;
    }

    private function courseMetadata(array $pages, AcademicSource $source): array
    {
        $text = collect($pages)->pluck('text')->implode("\n");
        $firstLine = $this->clean((string) (preg_split('/\R/u', $text)[0] ?? ''));
        $sourceContext = preg_split('/^Unit\s+\d+\s*[-\x{2013}\x{2014}:]/miu', $text, 2)[0] ?? '';
        preg_match('/^Assessment Philosophy\s*:?\s*(.*?)(?=^PERFORMANCE LEVELS:|^DEBUGGING EXPECTATION|^Unit\s+Sequence\s+at\s+a\s+Glance)/msiu', $text, $assessmentPolicy);
        preg_match('/^Instructional Guidance for the Teacher\/Parent\s*(.*?)(?=^Curriculum Import Structure)/msiu', $text, $instructionalGuidance);
        return array_filter([
            'title' => $firstLine ?: $source->title,
            'grade' => $source->gradeLevel?->name,
            'school_year' => $source->schoolYear?->name,
            'document_type' => $this->labeledValue($text, 'DOCUMENT TYPE'),
            'suggested_schedule' => $this->labeledValue($text, 'SUGGESTED SCHEDULE'),
            'course_purpose' => $this->inlineValue($text, 'COURSE PURPOSE'),
            'final_outcome' => $this->inlineValue($text, 'FINAL OUTCOME'),
            'session_pattern' => $this->inlineValue($text, 'SESSION PATTERN'),
            'assessment_policy_heading' => preg_match('/^Assessment Philosophy(?:\s*:|$)/miu', $text) ? 'Assessment Philosophy' : null,
            'assessment_policy' => isset($assessmentPolicy[1]) ? trim($assessmentPolicy[1]) : null,
            'instructional_guidance' => isset($instructionalGuidance[1]) ? trim($instructionalGuidance[1]) : null,
            'source_context' => trim($sourceContext),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function labeledValue(string $text, string $label): ?string
    {
        if (! preg_match('/^'.preg_quote($label, '/').'(?:[ \t]+([^\r\n]+)|\s*\R\s*([^\r\n]+))/miu', $text, $match)) return null;
        return $this->clean((string) (($match[1] ?? '') ?: ($match[2] ?? ''))) ?: null;
    }

    private function inlineValue(string $text, string $label): ?string
    {
        return preg_match('/^'.preg_quote($label, '/').'\s*:\s*([^\r\n]+)/miu', $text, $match) ? $this->clean($match[1]) : null;
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
