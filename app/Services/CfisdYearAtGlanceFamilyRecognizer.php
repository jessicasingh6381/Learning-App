<?php

namespace App\Services;

use App\Models\AcademicSource;
use App\Models\Subject;

final class CfisdYearAtGlanceFamilyRecognizer
{
    public function sourceMatches(AcademicSource $source, array $subjectCodes, array $gradeCodes): bool
    {
        $source->loadMissing(['educationProvider', 'gradeLevel', 'links']);
        $subjectId = $source->links->firstWhere('link_type', 'subject')?->link_id;
        $subjectCode = $subjectId ? Subject::query()->whereKey($subjectId)->value('code') : null;

        return in_array($subjectCode, $subjectCodes, true)
            && (in_array($source->gradeLevel?->code, $gradeCodes, true) || in_array($source->gradeLevel?->name, $gradeCodes, true))
            && (! $source->educationProvider || in_array($source->educationProvider->short_name, ['CFISD', null], true)
                || $source->educationProvider->name === 'Cypress-Fairbanks Independent School District')
            && in_array($source->source_category, ['curriculum', 'pacing', 'scope_and_sequence'], true);
    }

    public function hasPositionedColumns(array $pages, array $labels): bool
    {
        return collect($pages)->every(function (array $page) use ($labels): bool {
            $items = collect($page['items'] ?? []);
            return collect($labels)->every(fn (string $label) => $items->contains(
                fn (array $item) => strcasecmp(trim((string) ($item['text'] ?? '')), $label) === 0
            ));
        });
    }
}
