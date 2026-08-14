<?php

namespace Tests\Unit;

use App\Models\LessonResource;
use App\Services\CensusStateGeometryResourceProvider;
use App\Services\CensusPopulationDensityResourceProvider;
use App\Services\LessonResourceAssetValidator;
use App\Services\UsgsTopographyResourceProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class CensusStateGeometryResourceProviderTest extends TestCase
{
    public function test_provider_converts_only_authoritative_state_features_and_validator_requires_all_states(): void
    {
        Http::fake([
            'https://www2.census.gov/geo/tiger/GENZ2025/kml/cb_2025_us_state_20m.zip' => Http::response($this->archive()),
        ]);
        $resource = new LessonResource([
            'category' => 'lesson_resource', 'resource_type' => 'interactive_us_map',
            'delivery_type' => 'interactive', 'availability_status' => 'needs_asset',
        ]);

        $asset = app(CensusStateGeometryResourceProvider::class)->fulfill($resource);
        $geoJson = json_decode($asset->contents, true, flags: JSON_THROW_ON_ERROR);
        $validation = app(LessonResourceAssetValidator::class)->validate($asset);

        $this->assertSame('application/geo+json', $asset->mimeType);
        $this->assertSame('2025 Cartographic Boundary File — States, 1:20,000,000', $asset->providerMetadata['dataset_title']);
        $this->assertSame('explore', $asset->providerMetadata['supported_modes'][0]);
        $this->assertCount(51, $geoJson['features']);
        $this->assertSame('01', $geoJson['features'][0]['id']);
        $this->assertSame('Alabama', $geoJson['features'][0]['properties']['name']);
        $this->assertSame('MultiPolygon', $geoJson['features'][0]['geometry']['type']);
        $this->assertSame(51, $validation['feature_count']);
        $this->assertTrue($validation['unique_state_identifiers']);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www2.census.gov/geo/tiger/GENZ2025/'));
    }

    public function test_interactive_delivery_type_is_supported(): void
    {
        $this->assertContains('interactive', LessonResource::DELIVERY_TYPES);
        $this->assertTrue(app(CensusStateGeometryResourceProvider::class)->supports(new LessonResource([
            'category' => 'lesson_resource', 'resource_type' => 'interactive_us_map', 'delivery_type' => 'interactive',
        ])));
    }

    public function test_usgs_topography_provider_retrieves_and_validates_a_public_domain_instructional_image(): void
    {
        $image = imagecreatetruecolor(1200, 700);
        imagefill($image, 0, 0, imagecolorallocate($image, 90, 150, 90));
        ob_start(); imagejpeg($image, null, 85); $contents = ob_get_clean(); imagedestroy($image);
        Http::fake(['https://d9-wret.s3.us-west-2.amazonaws.com/*' => Http::response($contents, 200, ['Content-Type' => 'image/jpeg'])]);
        $resource = new LessonResource([
            'category' => 'lesson_resource', 'resource_type' => 'physical_us_map',
            'delivery_type' => 'viewable', 'availability_status' => 'needs_asset',
        ]);

        $provider = app(UsgsTopographyResourceProvider::class);
        $asset = $provider->fulfill($resource);
        $validation = app(LessonResourceAssetValidator::class)->validate($asset);

        $this->assertTrue($provider->supports($resource));
        $this->assertSame('image/jpeg', $asset->mimeType);
        $this->assertSame('U.S. Public Domain', $asset->licenseName);
        $this->assertSame('CONUS Topography Map', $asset->providerMetadata['provider_title']);
        $this->assertSame(1200, $validation['width_pixels']);
        $this->assertSame(700, $validation['height_pixels']);
        $this->assertTrue($validation['image_valid']);
    }

    public function test_census_population_density_provider_combines_keyless_population_csv_and_land_area_with_stable_provenance(): void
    {
        $populationRows = ['Name,Geography Type,Year,Resident Population,Percent Change in Resident Population,Resident Population Density'];
        $areaRows = ["USPS\tGEOID\tNAME\tALAND_SQMI"];
        $names = ['01'=>'Alabama','02'=>'Alaska','04'=>'Arizona','05'=>'Arkansas','06'=>'California','08'=>'Colorado','09'=>'Connecticut','10'=>'Delaware','11'=>'District of Columbia','12'=>'Florida','13'=>'Georgia','15'=>'Hawaii','16'=>'Idaho','17'=>'Illinois','18'=>'Indiana','19'=>'Iowa','20'=>'Kansas','21'=>'Kentucky','22'=>'Louisiana','23'=>'Maine','24'=>'Maryland','25'=>'Massachusetts','26'=>'Michigan','27'=>'Minnesota','28'=>'Mississippi','29'=>'Missouri','30'=>'Montana','31'=>'Nebraska','32'=>'Nevada','33'=>'New Hampshire','34'=>'New Jersey','35'=>'New Mexico','36'=>'New York','37'=>'North Carolina','38'=>'North Dakota','39'=>'Ohio','40'=>'Oklahoma','41'=>'Oregon','42'=>'Pennsylvania','44'=>'Rhode Island','45'=>'South Carolina','46'=>'South Dakota','47'=>'Tennessee','48'=>'Texas','49'=>'Utah','50'=>'Vermont','51'=>'Virginia','53'=>'Washington','54'=>'West Virginia','55'=>'Wisconsin','56'=>'Wyoming'];
        foreach (CensusStateGeometryResourceProvider::STATE_FIPS as $index => $fips) {
            $populationRows[] = sprintf('%s,State,2020,"%s",1.0,1.0', $names[$fips], number_format(100000 + ($index * 1000)));
            $areaRows[] = "XX\t{$fips}\t{$names[$fips]}\t".(1000 + $index);
        }
        Http::fake([
            'https://www2.census.gov/programs-surveys/decennial/2020/data/apportionment/*' => Http::response(implode("\n", $populationRows)),
            'https://www2.census.gov/geo/docs/maps-data/data/gazetteer/*' => Http::response($this->textArchive(implode("\n", $areaRows))),
        ]);
        $resource = new LessonResource([
            'category' => 'lesson_resource', 'resource_type' => 'us_population_density_data',
            'delivery_type' => 'embedded', 'availability_status' => 'needs_asset',
        ]);

        $provider = app(CensusPopulationDensityResourceProvider::class);
        $asset = $provider->fulfill($resource);
        $data = json_decode($asset->contents, true, flags: JSON_THROW_ON_ERROR);
        $validation = app(LessonResourceAssetValidator::class)->validate($asset);

        $this->assertTrue($provider->supports($resource));
        $this->assertSame('application/json', $asset->mimeType);
        $this->assertCount(51, $data['states']);
        $this->assertSame('01', $data['states'][0]['state_fips']);
        $this->assertSame(100.0, $data['states'][0]['density_per_sq_mile']);
        $this->assertTrue($validation['population_density_valid']);
        $this->assertSame('U.S. Government public data', $asset->licenseName);
    }

    private function archive(): string
    {
        $states = [
            '01'=>'Alabama','02'=>'Alaska','04'=>'Arizona','05'=>'Arkansas','06'=>'California','08'=>'Colorado','09'=>'Connecticut','10'=>'Delaware','11'=>'District of Columbia','12'=>'Florida','13'=>'Georgia','15'=>'Hawaii','16'=>'Idaho','17'=>'Illinois','18'=>'Indiana','19'=>'Iowa','20'=>'Kansas','21'=>'Kentucky','22'=>'Louisiana','23'=>'Maine','24'=>'Maryland','25'=>'Massachusetts','26'=>'Michigan','27'=>'Minnesota','28'=>'Mississippi','29'=>'Missouri','30'=>'Montana','31'=>'Nebraska','32'=>'Nevada','33'=>'New Hampshire','34'=>'New Jersey','35'=>'New Mexico','36'=>'New York','37'=>'North Carolina','38'=>'North Dakota','39'=>'Ohio','40'=>'Oklahoma','41'=>'Oregon','42'=>'Pennsylvania','44'=>'Rhode Island','45'=>'South Carolina','46'=>'South Dakota','47'=>'Tennessee','48'=>'Texas','49'=>'Utah','50'=>'Vermont','51'=>'Virginia','53'=>'Washington','54'=>'West Virginia','55'=>'Wisconsin','56'=>'Wyoming',
        ];
        $placemarks = '';
        foreach ($states as $fips => $name) {
            $longitude = -125 + ((int) $fips / 10);
            $coordinates = "{$longitude},30,0 ".($longitude + .1).",30,0 ".($longitude + .1).",30.1,0 {$longitude},30.1,0 {$longitude},30,0";
            $placemarks .= "<Placemark><ExtendedData><SchemaData><SimpleData name=\"STATEFP\">{$fips}</SimpleData><SimpleData name=\"GEOID\">{$fips}</SimpleData><SimpleData name=\"GEOIDFQ\">0400000US{$fips}</SimpleData><SimpleData name=\"STUSPS\">XX</SimpleData><SimpleData name=\"NAME\">{$name}</SimpleData></SchemaData></ExtendedData><Polygon><outerBoundaryIs><LinearRing><coordinates>{$coordinates}</coordinates></LinearRing></outerBoundaryIs></Polygon></Placemark>";
        }
        $kml = '<?xml version="1.0"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>'.$placemarks.'</Document></kml>';
        $temporary = tempnam(sys_get_temp_dir(), 'census-test-');
        $zip = new ZipArchive;
        $zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('cb_2025_us_state_5m.kml', $kml);
        $zip->close();
        $archive = file_get_contents($temporary);
        unlink($temporary);
        return $archive;
    }

    private function textArchive(string $text): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'census-area-test-');
        $zip = new ZipArchive;
        $zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('2024_Gaz_state_national.txt', $text);
        $zip->close();
        $archive = file_get_contents($temporary);
        unlink($temporary);
        return $archive;
    }
}
