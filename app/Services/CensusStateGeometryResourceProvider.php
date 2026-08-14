<?php

namespace App\Services;

use App\Contracts\LessonResourceFulfillmentProvider;
use App\Data\FulfilledLessonResourceData;
use App\Models\LessonResource;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class CensusStateGeometryResourceProvider implements LessonResourceFulfillmentProvider
{
    public const STATE_FIPS = [
        '01', '02', '04', '05', '06', '08', '09', '10', '11', '12', '13', '15', '16',
        '17', '18', '19', '20', '21', '22', '23', '24', '25', '26', '27', '28', '29',
        '30', '31', '32', '33', '34', '35', '36', '37', '38', '39', '40', '41', '42',
        '44', '45', '46', '47', '48', '49', '50', '51', '53', '54', '55', '56',
    ];

    public function key(): string { return 'census_state_geometry'; }
    public function strategy(): string { return 'authoritative_retrieval'; }

    public function supports(LessonResource $resource): bool
    {
        return $resource->category === 'lesson_resource'
            && $resource->resource_type === 'interactive_us_map'
            && $resource->delivery_type === 'interactive';
    }

    public function fulfill(LessonResource $resource): FulfilledLessonResourceData
    {
        $definition = config('lesson-resources.providers.census_state_geometry');
        $response = Http::accept('application/zip')->timeout(60)->get($definition['download_url']);
        if (! $response->successful()) {
            throw new RuntimeException("The authoritative geography provider returned HTTP {$response->status()}.");
        }
        $geoJson = $this->convertToGeoJson($this->extractKml($response->body()));

        return new FulfilledLessonResourceData(
            contents: json_encode($geoJson, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            filename: $definition['filename'], mimeType: 'application/geo+json',
            sourceUrl: $definition['source_url'], sourceAttribution: $definition['attribution'],
            licenseName: $definition['license_name'], licenseUrl: $definition['license_url'],
            providerMetadata: [
                'interactive_provider_type' => 'us_state_map',
                'dataset_title' => $definition['dataset_title'],
                'dataset_version' => $definition['dataset_version'],
                'retrieval_url' => $definition['download_url'],
                'supported_modes' => ['explore', 'map_tools', 'reference', 'builder'],
                'stable_identifier' => 'Census state FIPS GEOID',
                'source_format' => 'KML', 'delivered_format' => 'GeoJSON',
                'ring_orientation_conversion' => 'KML rings reversed for D3 spherical GeoJSON rendering',
            ],
        );
    }

    private function extractKml(string $archive): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'census-states-');
        if ($temporary === false || file_put_contents($temporary, $archive) === false) {
            throw new RuntimeException('The geography archive could not be prepared safely.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($temporary) !== true) {
                throw new RuntimeException('The geography archive is invalid.');
            }
            $entry = collect(range(0, $zip->numFiles - 1))->map(fn (int $index) => $zip->getNameIndex($index))
                ->first(fn ($name) => is_string($name) && str_ends_with(strtolower($name), '.kml'));
            $kml = $entry ? $zip->getFromName($entry) : false;
            $zip->close();
            if (! is_string($kml) || ! str_contains($kml, '<kml')) {
                throw new RuntimeException('The geography archive does not contain its expected KML data.');
            }
            return $kml;
        } finally {
            @unlink($temporary);
        }
    }

    private function convertToGeoJson(string $kml): array
    {
        $document = new DOMDocument;
        if (! @$document->loadXML($kml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException('The authoritative KML could not be parsed.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('kml', 'http://www.opengis.net/kml/2.2');
        $features = [];

        foreach ($xpath->query('//kml:Placemark') as $placemark) {
            if (! $placemark instanceof DOMElement) { continue; }
            $properties = [];
            foreach ($xpath->query('.//kml:SimpleData', $placemark) as $field) {
                if ($field instanceof DOMElement) { $properties[$field->getAttribute('name')] = trim($field->textContent); }
            }
            $stateFips = $properties['STATEFP'] ?? null;
            if (! in_array($stateFips, self::STATE_FIPS, true)) { continue; }

            $polygons = [];
            foreach ($xpath->query('.//kml:Polygon', $placemark) as $polygon) {
                $outer = $xpath->query('./kml:outerBoundaryIs/kml:LinearRing/kml:coordinates', $polygon)->item(0);
                if (! $outer) { continue; }
                $rings = [array_reverse($this->coordinates($outer->textContent))];
                foreach ($xpath->query('./kml:innerBoundaryIs/kml:LinearRing/kml:coordinates', $polygon) as $inner) {
                    $rings[] = array_reverse($this->coordinates($inner->textContent));
                }
                $polygons[] = $rings;
            }
            if ($polygons === []) { throw new RuntimeException("State {$stateFips} is missing authoritative polygon geometry."); }
            $features[] = [
                'type' => 'Feature', 'id' => $properties['GEOID'] ?? $stateFips,
                'properties' => [
                    'geoid' => $properties['GEOID'] ?? $stateFips,
                    'geoid_fq' => $properties['GEOIDFQ'] ?? null,
                    'state_fips' => $stateFips, 'abbreviation' => $properties['STUSPS'] ?? null,
                    'name' => $properties['NAME'] ?? null,
                ],
                'geometry' => ['type' => 'MultiPolygon', 'coordinates' => $polygons],
            ];
        }
        usort($features, fn (array $left, array $right) => strcmp($left['properties']['state_fips'], $right['properties']['state_fips']));
        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    private function coordinates(string $coordinates): array
    {
        $points = collect(preg_split('/\s+/', trim($coordinates)) ?: [])->filter()->map(function (string $coordinate): array {
            $parts = explode(',', $coordinate);
            if (count($parts) < 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
                throw new RuntimeException('The authoritative KML contains invalid coordinates.');
            }
            return [(float) $parts[0], (float) $parts[1]];
        })->values()->all();
        if (count($points) < 4 || $points[0] !== $points[array_key_last($points)]) {
            throw new RuntimeException('The authoritative KML contains an unclosed state boundary ring.');
        }
        return $points;
    }
}
