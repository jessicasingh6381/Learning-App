<?php

namespace App\Services;

use App\Contracts\StandardsDocumentParser;
use App\Data\CurriculumParserApplicability;
use App\Data\CurriculumParserResult;
use App\Data\CurriculumProposalData;
use App\Models\AcademicSource;
use Illuminate\Validation\ValidationException;

final class TexasTeksMultigradeSocialStudiesParser implements StandardsDocumentParser
{
    public const KEY = 'texas-teks-multigrade-social-studies';
    public const VERSION = 'texas-teks-multigrade-social-studies-v1';

    public function __construct(
        private MultigradeStandardsDocumentRecognizer $recognizer,
        private StandardsDocumentMetadataNormalizer $metadataNormalizer,
    ) {}
    public function key(): string { return self::KEY; }
    public function version(): string { return self::VERSION; }
    public function importType(): string { return 'standards'; }
    public function extractionMethod(): string { return 'pdf_text_sectioned'; }
    public function applicability(): CurriculumParserApplicability
    {
        return new CurriculumParserApplicability(
            providerCodes: [], subjectCodes: ['SS', 'SOCST', 'SOCIAL STUDIES'],
            gradeCodes: ['K', 'KG', 'G1', 'G2', 'G3', 'G4', 'G5', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Kindergarten'],
            sourceCategories: ['curriculum', 'standards'], mimeTypes: ['application/pdf'], extensions: ['pdf'],
            documentFamily: 'Texas TEKS Multi-grade Standards', priority: 120,
        );
    }
    public function matchingSections(array $pages, AcademicSource $source): array { return $this->recognizer->matchingSections($pages, $source); }
    public function supports(array $pages, AcademicSource $source): bool { return count($this->matchingSections($pages, $source)) === 1; }
    public function recognitionScore(array $pages, AcademicSource $source): float { return $this->supports($pages, $source) ? .995 : 0.0; }

    public function parse(array $pages, AcademicSource $source): CurriculumParserResult
    {
        $matches = $this->matchingSections($pages, $source);
        if (count($matches) !== 1) throw ValidationException::withMessages(['source' => count($matches) > 1
            ? 'More than one standards section matches the selected subject and grade.'
            : 'The selected subject and grade section is absent from this standards document.']);
        $match = $matches[0];
        $isolated = $this->recognizer->isolatedSection($pages, $match);
        $text = $isolated['text'];
        if (! preg_match('/\(a\)\s+Implementation\.\s*(?<implementation>.*?)\s*\(b\)\s+Introduction\.\s*(?<introduction>.*?)\s*\(c\)\s+Knowledge and skills\.\s*(?<standards>.*)$/su', $text, $parts)) {
            throw ValidationException::withMessages(['source' => 'The matching standards section does not contain the expected implementation, introduction, and knowledge-and-skills boundaries.']);
        }
        $gradeCode = $match['grade'] === 'Kindergarten' ? 'K' : preg_replace('/\D+/', '', $match['grade']);
        $implementation = $this->clean($parts['implementation']);
        $introduction = $this->clean($parts['introduction']);
        $standardsText = $parts['standards'];
        preg_match_all('/(?:^|\n|\s)\((?<number>\d+)\)\s+(?<body>.*?)(?=(?:\n|\s)\(\d+\)\s+[A-Z]|$)/su', $standardsText, $blocks, PREG_OFFSET_CAPTURE);
        $proposals = []; $strandKeys = []; $sequence = 0;
        $knowledgeOffset = strpos($text, '(c) Knowledge and skills.') + strlen('(c) Knowledge and skills.');
        foreach ($blocks['body'] as $index => [$bodyRaw, $bodyOffset]) {
            $number = $blocks['number'][$index][0];
            $body = $this->clean($bodyRaw);
            if (! preg_match('/^(?<strand>.+?)\.\s+(?<content>The student\b.*)$/su', $body, $bodyParts)) continue;
            $strand = $this->clean($bodyParts['strand']); $content = $this->clean($bodyParts['content']);
            $strandKey = 'strand:'.strtoupper(preg_replace('/[^a-z0-9]+/i', '-', $strand) ?? $strand);
            $blockOffset = $knowledgeOffset + $bodyOffset;
            $sourcePage = $this->recognizer->pageForOffset($isolated, $blockOffset);
            if (! isset($strandKeys[$strandKey])) {
                $strandKeys[$strandKey] = true;
                $proposals[] = new CurriculumProposalData(
                    $strandKey, null, 'strand', ++$sequence, $strand,
                    sourcePage: $sourcePage, rawText: $strand, parserNote: 'Strand label printed in the selected grade section.',
                    confidence: .995, parserMetadata: ['section' => $match['section']], strand: $strand,
                    normalizedCode: 'STRAND:'.strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', $strand) ?? $strand), statement: $strand,
                );
            }
            $split = preg_split('/\s+The student is expected to:\s*/iu', $content, 2);
            $parentStatement = $this->clean($split[0]); $expectationsText = $split[1] ?? '';
            $parentCode = $gradeCode.'.'.$number; $parentKey = 'standard:'.$parentCode;
            $proposals[] = new CurriculumProposalData(
                $parentKey, $strandKey, 'standard', ++$sequence, $parentCode,
                sourcePage: $sourcePage, rawText: $body, parserNote: 'Knowledge-and-skills statement from the isolated grade section.',
                confidence: .99, parserMetadata: ['section' => $match['section']], strand: $strand,
                standardCode: $parentCode, normalizedCode: $this->normalizeCode($parentCode), statement: $parentStatement,
            );
            preg_match_all('/(?:^|\s)\((?<letter>[A-Z])\)\s+(?<wording>.*?)(?=(?:\s\([A-Z]\)\s)|$)/su', $expectationsText, $expectations, PREG_OFFSET_CAPTURE);
            foreach ($expectations['wording'] as $expectationIndex => [$wordingRaw, $expectationOffset]) {
                $letter = $expectations['letter'][$expectationIndex][0];
                $wording = rtrim($this->clean($wordingRaw), "; "); $childCode = $parentCode.$letter;
                $childPage = $this->recognizer->pageForOffset($isolated, $blockOffset + $expectationOffset);
                $proposals[] = new CurriculumProposalData(
                    'expectation:'.$childCode, $parentKey, 'student_expectation', ++$sequence, $childCode,
                    sourcePage: $childPage, rawText: '(' . $letter . ') '.$wordingRaw,
                    parserNote: 'Lettered student expectation linked to its printed parent standard.', confidence: .99,
                    parserMetadata: ['section' => $match['section']], strand: $strand,
                    standardCode: $childCode, normalizedCode: $this->normalizeCode($childCode), statement: $wording,
                );
            }
        }
        if (collect($proposals)->where('proposalType', 'standard')->isEmpty()) {
            throw ValidationException::withMessages(['source' => 'No numbered knowledge-and-skills standards were recognized in the selected section.']);
        }
        $metadata = $this->metadataNormalizer->normalize([
            'document_family' => 'texas-teks-multigrade-standards', 'section' => $match['section'],
            'section_heading' => $match['heading'], 'adopted_label' => 'Adopted '.$match['adopted'],
            'implementation_statement' => $implementation, 'version_label' => 'August 2024 Update',
            'introduction_text' => $introduction, 'source_pages' => [$isolated['start_page'], $isolated['end_page']],
        ]);
        return new CurriculumParserResult(
            "{$match['grade']} {$match['subject']} TEKS", null, $match['grade'], $match['subject'], null, $proposals,
            diagnostic: "Only {$match['section']} ({$match['grade']}) was extracted from pages {$isolated['start_page']}-{$isolated['end_page']}; other grade sections were excluded.",
            metadata: $metadata,
        );
    }

    private function clean(string $value): string { return trim(preg_replace('/\s+/u', ' ', $value) ?? $value); }
    private function normalizeCode(string $code): string { return strtoupper(preg_replace('/[^A-Z0-9.]/i', '', $code) ?? $code); }
}
