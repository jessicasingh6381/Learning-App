<?php

namespace App\Services;

use App\Contracts\CurriculumOutlineParser;
use App\Contracts\StandardsDocumentParser;
use App\Data\CurriculumParserCapability;
use App\Models\AcademicSource;
use App\Models\AcademicSourceFile;
use App\Models\Subject;
use Illuminate\Support\Str;
use IteratorAggregate;
use RuntimeException;
use Traversable;

final class CurriculumParserRegistry implements IteratorAggregate
{
    public const CAPABILITY_CONTRACT_VERSION = 'curriculum-capability-v1';

    /** @var array<int, CurriculumOutlineParser> */
    private array $parsers;

    public function __construct(iterable $parsers)
    {
        $this->parsers = collect($parsers)->values()->all();
        foreach ($this->parsers as $parser) {
            if (! $parser instanceof CurriculumOutlineParser) {
                throw new \InvalidArgumentException('Every curriculum parser must implement CurriculumOutlineParser.');
            }
        }
        usort($this->parsers, fn ($left, $right) => [$left->key(), $left->version()] <=> [$right->key(), $right->version()]);
    }

    public function signature(): string
    {
        $identities = array_map(fn ($parser) => $parser->key().'|'.$parser->version(), $this->parsers);

        return hash('sha256', self::CAPABILITY_CONTRACT_VERSION.'|'.implode(';', $identities));
    }

    public function getIterator(): Traversable
    {
        yield from $this->parsers;
    }

    /** @return array<int, CurriculumOutlineParser> */
    public function applicable(AcademicSource $source, AcademicSourceFile $file): array
    {
        $source->loadMissing(['educationProvider', 'gradeLevel', 'links']);
        $subjectId = $source->links->firstWhere('link_type', 'subject')?->link_id;
        $subjectCode = $subjectId ? Subject::query()->whereKey($subjectId)->value('code') : null;
        $providerValues = array_filter([$source->educationProvider?->short_name, $source->educationProvider?->name]);
        $gradeValues = array_filter([$source->gradeLevel?->code, $source->gradeLevel?->name]);

        return array_values(array_filter($this->parsers, function (CurriculumOutlineParser $parser) use ($source, $file, $subjectCode, $providerValues, $gradeValues): bool {
            $meta = $parser->applicability();

            return $this->matches($meta->providerCodes, $providerValues, allowMissing: true)
                && $this->matches($meta->subjectCodes, [$subjectCode])
                && $this->matches($meta->gradeCodes, $gradeValues)
                && $this->matches($meta->sourceCategories, [$source->source_category])
                && $this->matches($meta->mimeTypes, [$file->mime_type])
                && $this->matches($meta->extensions, [$file->extension]);
        }));
    }

    public function assess(array $pages, AcademicSource $source, AcademicSourceFile $file): CurriculumParserCapability
    {
        $signature = $this->signature();
        $applicable = $this->applicable($source, $file);
        if ($applicable === []) {
            return $this->result('unsupported', $file, $signature,
                'This readable document format needs curriculum outline setup before extraction can begin.',
                'No registered parser declares compatibility with the source context.');
        }

        $ambiguousSectionParser = collect($applicable)->first(fn (CurriculumOutlineParser $parser) =>
            $parser instanceof StandardsDocumentParser && count($parser->matchingSections($pages, $source)) > 1
        );
        if ($ambiguousSectionParser) {
            return $this->result('ambiguous', $file, $signature,
                'This standards document contains more than one section matching the selected subject and grade.',
                'Multiple matching grade-section boundaries were found; no section was selected.',
                $this->publicCandidates([$ambiguousSectionParser]));
        }

        $matches = collect($applicable)->map(function (CurriculumOutlineParser $parser) use ($pages, $source): array {
            $score = max(0.0, min(1.0, $parser->recognitionScore($pages, $source)));

            return [
                'parser' => $parser, 'key' => $parser->key(), 'version' => $parser->version(),
                'priority' => $parser->applicability()->priority, 'score' => $score,
                'document_family' => $parser->applicability()->documentFamily,
            ];
        })->filter(fn (array $candidate) => $candidate['score'] > 0)->values();

        if ($matches->isEmpty()) {
            return $this->result('unsupported', $file, $signature,
                'This readable document format needs curriculum outline setup before extraction can begin.',
                'Applicable parsers were evaluated, but none recognized the required text and positioned layout.',
                $this->publicCandidates($applicable));
        }

        $highestPriority = $matches->max('priority');
        $finalists = $matches->where('priority', $highestPriority)->values();
        if ($finalists->count() !== 1) {
            return $this->result('ambiguous', $file, $signature,
                'This document matches more than one supported format and needs review before extraction.',
                'Multiple parsers matched at the same highest priority.',
                $finalists->map(fn ($item) => collect($item)->except('parser')->all())->all());
        }

        $selected = $finalists->first();
        /** @var CurriculumOutlineParser $parser */
        $parser = $selected['parser'];

        return new CurriculumParserCapability(
            'supported', $parser->key(), $parser->version(), $parser->extractionMethod(),
            $selected['score'], 'Outline extraction is supported for this document.', null,
            $matches->map(fn ($item) => collect($item)->except('parser')->all())->all(),
            $file->id, $file->checksum_sha256, $signature, now(), $selected['document_family'],
        );
    }

    public function parser(string $key, string $version): ?CurriculumOutlineParser
    {
        return collect($this->parsers)->first(fn ($parser) => $parser->key() === $key && $parser->version() === $version);
    }

    public function select(array $pages, AcademicSource $source): CurriculumOutlineParser
    {
        $file = $source->currentFile;
        if (! $file) throw new RuntimeException('No current curriculum PDF is available.');
        $capability = $this->assess($pages, $source, $file);
        if (! $capability->supported()) throw new RuntimeException($capability->userMessage);

        return $this->parser($capability->parserKey, $capability->parserVersion)
            ?? throw new RuntimeException('The recognized curriculum parser is no longer registered.');
    }

    private function result(string $state, AcademicSourceFile $file, string $signature, string $message, string $diagnostic, array $candidates = []): CurriculumParserCapability
    {
        return new CurriculumParserCapability(
            $state, null, null, null, null, $message, $diagnostic, $candidates,
            $file->id, $file->checksum_sha256, $signature, now(),
        );
    }

    private function publicCandidates(array $parsers): array
    {
        return array_map(fn ($parser) => [
            'key' => $parser->key(), 'version' => $parser->version(),
            'priority' => $parser->applicability()->priority,
            'document_family' => $parser->applicability()->documentFamily,
        ], $parsers);
    }

    private function matches(array $expected, array $actual, bool $allowMissing = false): bool
    {
        if ($expected === []) return true;
        $actual = array_values(array_filter($actual, fn ($value) => $value !== null && $value !== ''));
        if ($actual === []) return $allowMissing;
        $normalize = fn ($value) => Str::lower(preg_replace('/[^a-z0-9]+/i', '', (string) $value) ?? (string) $value);
        $expected = array_map($normalize, $expected);

        return collect($actual)->map($normalize)->contains(fn ($value) => in_array($value, $expected, true));
    }
}
