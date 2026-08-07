<?php

namespace App\Services;

use App\Contracts\CurriculumOutlineParser;
use App\Data\CurriculumParserApplicability;
use App\Data\CurriculumParserResult;
use App\Data\CurriculumProposalData;
use App\Models\AcademicSource;
use App\Models\CurriculumFormatProfile;
use Illuminate\Support\Str;

final class DeclarativeCurriculumFormatParser implements CurriculumOutlineParser
{
    public function __construct(public readonly CurriculumFormatProfile $profile, private CurriculumDocumentStructureDetector $detector) { $this->profile->loadMissing(['provider', 'subject', 'minimumGrade', 'maximumGrade']); }

    public function supports(array $pages, AcademicSource $source): bool { return $this->recognitionScore($pages, $source) > 0; }

    public function recognitionScore(array $pages, AcademicSource $source): float
    {
        if ($this->profile->status !== 'active') return 0.0;
        $fingerprints = $this->profile->recognition_fingerprints;
        if (($fingerprints['page_count'] ?? null) && count($pages) !== (int) $fingerprints['page_count']) return 0.0;
        $text = mb_strtolower(collect($pages)->pluck('text')->implode("\n"));
        foreach ($fingerprints['required_text'] ?? [] as $required) if (! str_contains($text, mb_strtolower((string) $required))) return 0.0;
        $items = collect($pages)->flatMap(fn ($page) => collect($page['items'] ?? [])->pluck('text'))->map(fn ($value) => mb_strtolower(trim((string) $value)));
        foreach ($fingerprints['required_columns'] ?? [] as $column) if (! $items->contains(mb_strtolower((string) $column))) return 0.0;
        return .9;
    }

    public function applicability(): CurriculumParserApplicability
    {
        return new CurriculumParserApplicability(
            providerCodes: array_values(array_filter([$this->profile->provider?->short_name, $this->profile->provider?->name])),
            subjectCodes: array_values(array_filter([$this->profile->subject?->code])),
            gradeCodes: array_values(array_filter([$this->profile->minimumGrade?->code, $this->profile->minimumGrade?->name, $this->profile->maximumGrade?->code, $this->profile->maximumGrade?->name])),
            sourceCategories: ['curriculum', 'pacing', 'scope_and_sequence'], mimeTypes: [$this->profile->file_type], extensions: ['pdf'],
            documentFamily: $this->profile->document_family, priority: 50,
        );
    }

    public function parse(array $pages, AcademicSource $source): CurriculumParserResult
    {
        if (! $this->supports($pages, $source)) throw new \RuntimeException('The PDF no longer matches the active declarative format profile.');
        $detected = $this->detector->detect($pages, $source);
        $rules = $this->profile->mapping_rules;
        $periodRows = collect($detected['headings'])->filter(fn ($row) => preg_match('/(?:grading period|nine weeks|semester|quarter)/iu', $row))->values();
        if ($periodRows->isEmpty()) $periodRows = collect($rules['confirmed_period_headings'] ?? []);
        $proposals = [];
        foreach ($periodRows as $index => $row) $proposals[] = new CurriculumProposalData(
            'period:'.($index + 1), null, 'period', $index + 1, $this->periodName($row, $index + 1),
            reportingPeriod: $this->periodName($row, $index + 1), sourcePage: $this->pageFor($pages, $row), rawText: $row,
            parserNote: 'Detected by an authorized declarative format profile; verify this reporting-period heading.', confidence: .78,
            parserMetadata: ['format_profile_id' => $this->profile->id, 'profile_version' => $this->profile->profile_version],
        );
        if ($periodRows->isEmpty()) throw new \RuntimeException('The active format profile did not detect a reporting-period heading.');

        $unitRows = collect($detected['unit_rows'])->reject(fn ($row) => preg_match('/^Unit\s*\(?(?:TEKS|Standards)/iu', $row))->unique()->values();
        foreach ($unitRows as $index => $row) {
            $name = trim(preg_replace('/\s*\((?:TEKS|Standards)[^)]+\)\s*/iu', ' ', $row) ?? $row);
            preg_match_all('/\b\d{1,2}\.\d{1,2}[A-Z]?(?:\s*-\s*\d{1,2}\.\d{1,2}[A-Z]?)?\b/u', $row, $codes);
            $proposals[] = new CurriculumProposalData(
                'unit:'.($index + 1), 'period:1', 'unit', $index + 1, $name, unitType: 'instructional', reportingPeriod: $this->periodName($periodRows->first(), 1),
                standardCodes: collect($codes[0] ?? [])->unique()->values()->all(), sourcePage: $this->pageFor($pages, $row), rawText: $row,
                parserNote: 'Detected by an authorized declarative format profile. Placement and wording require saved human review before approval.', confidence: .7,
                parserMetadata: ['format_profile_id' => $this->profile->id, 'profile_version' => $this->profile->profile_version],
            );
        }
        foreach (collect($detected['assessment_rows'])->unique()->values() as $index => $row) $proposals[] = new CurriculumProposalData(
            'assessment:'.($index + 1), 'period:1', 'assessment', 500 + $index, Str::limit($row, 255, ''), unitType: 'assessment',
            reportingPeriod: $this->periodName($periodRows->first(), 1), sourcePage: $this->pageFor($pages, $row), rawText: $row,
            parserNote: 'Assessment-like row detected by an authorized declarative format profile; confirm inclusion and dates.', confidence: .65,
            parserMetadata: ['format_profile_id' => $this->profile->id, 'profile_version' => $this->profile->profile_version],
        );
        if (collect($proposals)->where('proposalType', 'unit')->isEmpty()) throw new \RuntimeException('The active format profile did not detect a reviewable unit row.');

        return new CurriculumParserResult(
            (string) ($detected['title'] ?: $source->title), null, $source->gradeLevel?->name, $this->profile->subject?->name,
            $source->schoolYear?->name, $proposals,
            'This outline was created by a declarative format profile. Save a complete review before approval; low-confidence rows remain flagged.',
        );
    }

    public function key(): string { return 'format-profile-'.$this->profile->id; }
    public function version(): string { return 'profile-'.$this->profile->id.'-v'.$this->profile->profile_version; }
    public function extractionMethod(): string { return 'declarative_profile'; }
    private function periodName(string $row, int $sequence): string { return trim(preg_replace('/\s*\(.*$/u', '', $row) ?? $row) ?: "Reporting Period {$sequence}"; }
    private function pageFor(array $pages, string $needle): int { foreach ($pages as $page) if (str_contains((string) ($page['text'] ?? ''), $needle)) return (int) ($page['page'] ?? 1); return 1; }
}
