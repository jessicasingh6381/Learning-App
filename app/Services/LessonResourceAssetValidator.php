<?php

namespace App\Services;

use App\Data\FulfilledLessonResourceData;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

class LessonResourceAssetValidator
{
    public function validate(FulfilledLessonResourceData $asset): array
    {
        $bytes = strlen($asset->contents);
        if ($bytes < 1024 || $bytes > (int) config('lesson-resources.maximum_bytes')) {
            throw new RuntimeException('The retrieved resource has an unsafe file size.');
        }
        if ($asset->mimeType === 'application/geo+json') {
            return $this->validateGeoJson($asset->contents);
        }
        if ($asset->mimeType === 'application/json') {
            if (($asset->providerMetadata['resource_schema'] ?? null) === 'spanish_instructional_resource_v1') {
                return $this->validateSpanishInstructionalResource($asset->contents);
            }
            if (($asset->providerMetadata['resource_schema'] ?? null) === 'technology_instructional_resource_v1') {
                return $this->validateTechnologyInstructionalResource($asset->contents);
            }
            if (($asset->providerMetadata['resource_schema'] ?? null) === 'elar_instructional_resource_v1') {
                return $this->validateElarInstructionalResource($asset->contents);
            }
            return $this->validatePopulationDensity($asset->contents);
        }
        if (in_array($asset->mimeType, ['image/jpeg', 'image/png'], true)) {
            return $this->validateImage($asset->contents, $asset->mimeType);
        }
        if ($asset->mimeType !== 'application/pdf' || ! str_starts_with($asset->contents, '%PDF-')) {
            throw new RuntimeException('The retrieved resource is not a valid PDF document.');
        }

        if (! str_contains(substr($asset->contents, -2048), '%%EOF')
            || ! preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/s', $asset->contents, $xrefMatch)) {
            throw new RuntimeException('The retrieved PDF is structurally incomplete.');
        }
        $xrefOffset = (int) $xrefMatch[1];
        $xrefTarget = substr($asset->contents, $xrefOffset, 256);
        if (! str_starts_with($xrefTarget, 'xref') && ! str_contains($xrefTarget, '/Type/XRef') && ! str_contains($xrefTarget, '/Type /XRef')) {
            throw new RuntimeException('The retrieved PDF cross-reference is invalid.');
        }
        $declaredPages = preg_match_all('/\/Type\s*\/Page(?!s)\b/', $asset->contents);
        if ($declaredPages !== 1) {
            throw new RuntimeException('The vetted map resource must contain exactly one printable page.');
        }

        $parserParseable = false;
        $details = [];
        try {
            $pages = (new Parser)->parseContent($asset->contents)->getPages();
            $parserParseable = count($pages) === 1;
            $details = $parserParseable ? $pages[0]->getDetails() : [];
        } catch (Throwable) {
            // Some vetted image-based PDFs cannot be text-extracted by Smalot. The
            // independent PDF structure, page count, xref, and media box checks remain mandatory.
        }

        $mediaBox = $this->mediaBox($details);
        if ($mediaBox === null && preg_match('/\/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]/', $asset->contents, $matches)) {
            $mediaBox = array_map('floatval', array_slice($matches, 1));
        }
        if ($mediaBox === null) {
            throw new RuntimeException('The retrieved PDF does not declare a printable page size.');
        }

        $widthPoints = abs($mediaBox[2] - $mediaBox[0]);
        $heightPoints = abs($mediaBox[3] - $mediaBox[1]);
        if ($widthPoints < 500 || $heightPoints < 500) {
            throw new RuntimeException('The retrieved map is too small for practical printing.');
        }

        return [
            'validation_version' => 1,
            'page_count' => 1,
            'page_width_points' => round($widthPoints, 2),
            'page_height_points' => round($heightPoints, 2),
            'page_width_inches' => round($widthPoints / 72, 2),
            'page_height_inches' => round($heightPoints / 72, 2),
            'pdf_structurally_valid' => true,
            'text_parser_compatible' => $parserParseable,
        ];
    }

    private function validateTechnologyInstructionalResource(string $contents): array
    {
        try { $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new RuntimeException('The generated Technology instructional resource is not valid JSON.'); }
        if (($data['schema'] ?? null) !== 'technology_instructional_resource_v1'
            || ($data['kind'] ?? null) !== 'interactive_reference'
            || count($data['concepts'] ?? []) < 4
            || ($data['safe_preview']['not_execution'] ?? null) !== true
            || ! is_string($data['starter_code'] ?? null)
            || ! str_contains($data['starter_code'], 'print(')) {
            throw new RuntimeException('The generated Technology instructional resource is incomplete.');
        }
        return ['validation_version' => 1, 'technology_instructional_resource_valid' => true, 'kind' => 'interactive_reference'];
    }

    private function validateSpanishInstructionalResource(string $contents): array
    {
        try { $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new RuntimeException('The generated Spanish instructional resource is not valid JSON.'); }
        $phrases = $data['phrases'] ?? null;
        if (($data['schema'] ?? null) !== 'spanish_instructional_resource_v1'
            || ($data['kind'] ?? null) !== 'interactive_phrase_reference'
            || ! is_array($phrases) || count($phrases) < 3 || count($phrases) > 15
            || collect($phrases)->contains(fn ($phrase) => ! is_string($phrase['spanish'] ?? null) || ! is_string($phrase['meaning'] ?? null) || ! is_string($phrase['pronunciation_aid'] ?? null))
            || ($data['speech_support']['records_student_audio'] ?? null) !== false
            || ($data['speech_support']['scores_pronunciation'] ?? null) !== false) {
            throw new RuntimeException('The generated Spanish instructional resource is incomplete.');
        }
        return ['validation_version' => 1, 'spanish_instructional_resource_valid' => true, 'phrase_count' => 5, 'records_student_audio' => false];
    }

    private function validateElarInstructionalResource(string $contents): array
    {
        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('The generated ELAR instructional resource is not valid JSON.');
        }
        if (($data['schema'] ?? null) !== 'elar_instructional_resource_v1'
            || ! in_array($data['kind'] ?? null, ['reading_passage', 'interactive_reference'], true)
            || ! is_string($data['provenance'] ?? null)
            || mb_strlen($data['provenance']) < 20) {
            throw new RuntimeException('The generated ELAR instructional resource is incomplete.');
        }
        if (($data['kind'] ?? null) === 'reading_passage') {
            $paragraphs = $data['content']['paragraphs'] ?? null;
            if (! is_array($paragraphs) || count($paragraphs) < 3
                || collect($paragraphs)->contains(fn ($paragraph) => ! is_array($paragraph['sentences'] ?? null) || $paragraph['sentences'] === [])) {
                throw new RuntimeException('The generated ELAR passage is incomplete.');
            }
        } else {
            $legacyToolkit = count($data['routine'] ?? []) === 4 && count($data['syllable_patterns'] ?? []) === 4;
            $skillGuide = is_array($data['concepts'] ?? null) && count($data['concepts']) >= 3
                && is_array($data['workflow'] ?? null) && count($data['workflow']) >= 4;
            if (! $legacyToolkit && ! $skillGuide) {
                throw new RuntimeException('The generated ELAR reference is incomplete.');
            }
        }

        return ['validation_version' => 1, 'elar_instructional_resource_valid' => true, 'kind' => $data['kind']];
    }

    private function validateImage(string $contents, string $declaredMimeType): array
    {
        $details = @getimagesizefromstring($contents);
        if (! is_array($details) || ! isset($details[0], $details[1], $details['mime'])
            || $details['mime'] !== $declaredMimeType
            || $details[0] < 1200 || $details[1] < 700) {
            throw new RuntimeException('The retrieved map image is invalid or too small for instruction.');
        }

        return [
            'validation_version' => 1,
            'image_valid' => true,
            'width_pixels' => $details[0],
            'height_pixels' => $details[1],
            'detected_mime_type' => $details['mime'],
        ];
    }

    private function validatePopulationDensity(string $contents): array
    {
        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('The retrieved population-density resource is not valid JSON.');
        }
        $states = $data['states'] ?? null;
        if (! is_array($states) || count($states) !== 51 || ($data['dataset']['unit'] ?? null) !== 'people per square mile') {
            throw new RuntimeException('The population-density resource must contain all states and D.C. with its unit.');
        }
        $identifiers = [];
        foreach ($states as $state) {
            $fips = $state['state_fips'] ?? null;
            if (! is_string($fips) || ! in_array($fips, CensusStateGeometryResourceProvider::STATE_FIPS, true)
                || ! is_string($state['name'] ?? null) || $state['name'] === ''
                || ! is_int($state['population'] ?? null) || $state['population'] < 1
                || ! is_numeric($state['land_area_sq_miles'] ?? null) || $state['land_area_sq_miles'] <= 0
                || ! is_numeric($state['density_per_sq_mile'] ?? null) || $state['density_per_sq_mile'] <= 0) {
                throw new RuntimeException('A population-density record is incomplete or invalid.');
            }
            $identifiers[] = $fips;
        }
        if (count(array_unique($identifiers)) !== 51) throw new RuntimeException('The population-density resource contains duplicate state identifiers.');
        return ['validation_version' => 1, 'population_density_valid' => true, 'feature_count' => 51, 'unique_state_identifiers' => true, 'unit' => 'people per square mile'];
    }

    private function validateGeoJson(string $contents): array
    {
        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('The retrieved geographic resource is not valid GeoJSON.');
        }
        if (($data['type'] ?? null) !== 'FeatureCollection' || count($data['features'] ?? []) !== 51) {
            throw new RuntimeException('The geographic resource must contain the 50 states and District of Columbia exactly once.');
        }

        $identifiers = [];
        $names = [];
        $coordinateCount = 0;
        foreach ($data['features'] as $feature) {
            $identifier = $feature['properties']['state_fips'] ?? null;
            $name = $feature['properties']['name'] ?? null;
            if (! is_string($identifier) || ! preg_match('/^\d{2}$/', $identifier)
                || ! is_string($name) || $name === ''
                || ($feature['geometry']['type'] ?? null) !== 'MultiPolygon') {
                throw new RuntimeException('A geographic feature is missing its stable identifier, name, or polygon geometry.');
            }
            $identifiers[] = $identifier;
            $names[] = $name;
            $coordinateCount += $this->validateCoordinateTree($feature['geometry']['coordinates'] ?? []);
        }
        if (count(array_unique($identifiers)) !== 51 || count(array_unique($names)) !== 51) {
            throw new RuntimeException('The geographic resource contains duplicate state identifiers or names.');
        }

        return [
            'validation_version' => 1, 'geojson_valid' => true, 'feature_count' => 51,
            'coordinate_count' => $coordinateCount, 'unique_state_identifiers' => true,
            'geometry_types' => ['MultiPolygon'],
        ];
    }

    private function validateCoordinateTree(array $polygons): int
    {
        $count = 0;
        foreach ($polygons as $rings) {
            if (! is_array($rings) || $rings === []) { throw new RuntimeException('A state polygon has no boundary rings.'); }
            foreach ($rings as $ring) {
                if (! is_array($ring) || count($ring) < 4 || $ring[0] !== $ring[array_key_last($ring)]) {
                    throw new RuntimeException('A state boundary ring is incomplete.');
                }
                foreach ($ring as $coordinate) {
                    if (! is_array($coordinate) || count($coordinate) !== 2
                        || ! is_numeric($coordinate[0]) || ! is_numeric($coordinate[1])
                        || $coordinate[0] < -180 || $coordinate[0] > 180
                        || $coordinate[1] < -90 || $coordinate[1] > 90) {
                        throw new RuntimeException('A state boundary contains an invalid longitude or latitude.');
                    }
                    $count++;
                }
            }
        }
        return $count;
    }

    private function mediaBox(array $details): ?array
    {
        $candidate = $details['MediaBox'] ?? $details['mediaBox'] ?? null;
        if (is_array($candidate) && count($candidate) === 4 && collect($candidate)->every('is_numeric')) {
            return array_map('floatval', array_values($candidate));
        }

        return null;
    }
}
