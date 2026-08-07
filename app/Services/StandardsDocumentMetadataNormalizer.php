<?php

namespace App\Services;

final class StandardsDocumentMetadataNormalizer
{
    public function normalize(array $metadata, ?string $adoptedLabel = null): array
    {
        $implementation = $this->whitespace($metadata['implementation_statement'] ?? $metadata['implementation_label'] ?? null);

        return [
            ...$metadata,
            'adopted_label' => $this->whitespace($adoptedLabel ?? $metadata['adopted_label'] ?? null),
            'version_label' => $this->whitespace($metadata['version_label'] ?? $metadata['update_label'] ?? null),
            'effective_label' => $this->effectiveLabel($metadata['effective_label'] ?? $implementation),
            'implementation_statement' => $implementation,
        ];
    }

    public function effectiveLabel(?string $value): ?string
    {
        $value = $this->whitespace($value);
        if (! $value) return null;
        if (preg_match('/\b(?<year>\d{4})\s*[-–—]\s*(?<next>\d{4})\s+school year\b/iu', $value, $match)) {
            return $match['year'].'-'.$match['next'].' school year';
        }
        if (preg_match('/\beffective(?:\s+on)?\s+(?<date>[A-Z][a-z]+\s+\d{1,2},\s+\d{4})\b/u', $value, $match)) {
            return $match['date'];
        }

        return null;
    }

    public function whitespace(?string $value): ?string
    {
        if ($value === null) return null;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        return $value === '' ? null : $value;
    }
}
