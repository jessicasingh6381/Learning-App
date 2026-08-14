<?php

namespace App\Services;

use App\Contracts\LessonResourceFulfillmentProvider;
use App\Data\FulfilledLessonResourceData;
use App\Models\LessonResource;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class CensusPopulationDensityResourceProvider implements LessonResourceFulfillmentProvider
{
    public function key(): string { return 'census_population_density'; }
    public function strategy(): string { return 'authoritative_retrieval'; }

    public function supports(LessonResource $resource): bool
    {
        return $resource->category === 'lesson_resource'
            && $resource->resource_type === 'us_population_density_data'
            && $resource->delivery_type === 'embedded';
    }

    public function fulfill(LessonResource $resource): FulfilledLessonResourceData
    {
        $definition = config('lesson-resources.providers.census_population_density');
        $populationResponse = Http::accept('text/csv')->timeout(45)->get($definition['population_url']);
        $areaResponse = Http::accept('application/zip')->timeout(45)->get($definition['area_url']);
        if (! $populationResponse->successful() || ! $areaResponse->successful()) {
            throw new RuntimeException('An authoritative Census population-density source could not be retrieved.');
        }

        $populations = $this->populations($populationResponse->body());
        $areas = $this->areas($this->extractGazetteer($areaResponse->body()));
        $states = [];
        foreach (CensusStateGeometryResourceProvider::STATE_FIPS as $fips) {
            if (! isset($populations[$fips], $areas[$fips]) || $areas[$fips] <= 0) {
                throw new RuntimeException("Census population-density inputs are incomplete for state {$fips}.");
            }
            $states[] = [
                'state_fips' => $fips, 'name' => $populations[$fips]['name'],
                'population' => $populations[$fips]['population'],
                'land_area_sq_miles' => round($areas[$fips], 2),
                'density_per_sq_mile' => round($populations[$fips]['population'] / $areas[$fips], 1),
            ];
        }

        $contents = json_encode([
            'dataset' => [
                'title' => $definition['dataset_title'], 'population_vintage' => '2020 Census resident population',
                'land_area_vintage' => '2024 Census Gazetteer', 'unit' => 'people per square mile',
                'caution' => 'A mapped pattern can support an inference but does not prove a single cause.',
            ],
            'states' => $states,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return new FulfilledLessonResourceData(
            contents: $contents, filename: $definition['filename'], mimeType: 'application/json',
            sourceUrl: $definition['source_url'], sourceAttribution: $definition['attribution'],
            licenseName: $definition['license_name'], licenseUrl: $definition['license_url'],
            providerMetadata: [
                'provider_title' => $definition['dataset_title'],
                'population_url' => $definition['population_url'], 'area_url' => $definition['area_url'],
                'calculation' => '2020 Census resident population divided by 2024 Gazetteer land area in square miles',
                'feature_count' => count($states), 'stable_identifier' => 'Census state FIPS',
            ],
        );
    }

    private function populations(string $csv): array
    {
        $lines = preg_split('/\R/', trim($csv)) ?: [];
        $headers = str_getcsv(array_shift($lines) ?: '');
        $nameIndex = array_search('Name', $headers, true);
        $yearIndex = array_search('Year', $headers, true);
        $populationIndex = array_search('Resident Population', $headers, true);
        if ($nameIndex === false || $yearIndex === false || $populationIndex === false) {
            throw new RuntimeException('The Census population response has an unexpected structure.');
        }
        $fipsByName = array_flip([
            '01'=>'Alabama','02'=>'Alaska','04'=>'Arizona','05'=>'Arkansas','06'=>'California','08'=>'Colorado','09'=>'Connecticut','10'=>'Delaware','11'=>'District of Columbia','12'=>'Florida','13'=>'Georgia','15'=>'Hawaii','16'=>'Idaho','17'=>'Illinois','18'=>'Indiana','19'=>'Iowa','20'=>'Kansas','21'=>'Kentucky','22'=>'Louisiana','23'=>'Maine','24'=>'Maryland','25'=>'Massachusetts','26'=>'Michigan','27'=>'Minnesota','28'=>'Mississippi','29'=>'Missouri','30'=>'Montana','31'=>'Nebraska','32'=>'Nevada','33'=>'New Hampshire','34'=>'New Jersey','35'=>'New Mexico','36'=>'New York','37'=>'North Carolina','38'=>'North Dakota','39'=>'Ohio','40'=>'Oklahoma','41'=>'Oregon','42'=>'Pennsylvania','44'=>'Rhode Island','45'=>'South Carolina','46'=>'South Dakota','47'=>'Tennessee','48'=>'Texas','49'=>'Utah','50'=>'Vermont','51'=>'Virginia','53'=>'Washington','54'=>'West Virginia','55'=>'Wisconsin','56'=>'Wyoming',
        ]);
        $values = [];
        foreach ($lines as $line) {
            $row = str_getcsv($line);
            $name = trim($row[$nameIndex] ?? '');
            $population = str_replace(',', '', trim($row[$populationIndex] ?? ''));
            $fips = $fipsByName[$name] ?? null;
            if (($row[$yearIndex] ?? null) === '2020' && $fips && is_numeric($population)) {
                $values[$fips] = ['name' => $name, 'population' => (int) $population];
            }
        }
        if (count($values) !== count(CensusStateGeometryResourceProvider::STATE_FIPS)) {
            throw new RuntimeException('The Census population response does not contain all expected states and the District of Columbia.');
        }
        return $values;
    }

    private function extractGazetteer(string $archive): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'census-area-');
        if ($temporary === false || file_put_contents($temporary, $archive) === false) {
            throw new RuntimeException('The Census land-area archive could not be prepared safely.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($temporary) !== true) throw new RuntimeException('The Census land-area archive is invalid.');
            $entry = collect(range(0, $zip->numFiles - 1))->map(fn (int $index) => $zip->getNameIndex($index))
                ->first(fn ($name) => is_string($name) && str_ends_with(strtolower($name), '.txt'));
            $text = $entry ? $zip->getFromName($entry) : false;
            $zip->close();
            if (! is_string($text) || ! str_contains($text, 'ALAND_SQMI')) throw new RuntimeException('The Census land-area table is missing.');
            return $text;
        } finally {
            @unlink($temporary);
        }
    }

    private function areas(string $text): array
    {
        $lines = preg_split('/\R/', trim($text)) ?: [];
        $headers = str_getcsv(array_shift($lines) ?: '', "\t");
        $fipsIndex = array_search('GEOID', $headers, true);
        $areaIndex = array_search('ALAND_SQMI', $headers, true);
        if ($fipsIndex === false || $areaIndex === false) throw new RuntimeException('The Census land-area columns are missing.');
        $values = [];
        foreach ($lines as $line) {
            $row = str_getcsv($line, "\t");
            $fips = trim($row[$fipsIndex] ?? '');
            $area = trim($row[$areaIndex] ?? '');
            if (in_array($fips, CensusStateGeometryResourceProvider::STATE_FIPS, true) && is_numeric($area)) $values[$fips] = (float) $area;
        }
        return $values;
    }
}
