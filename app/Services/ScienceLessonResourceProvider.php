<?php

namespace App\Services;

use App\Contracts\LessonResourceFulfillmentProvider;
use App\Data\FulfilledLessonResourceData;
use App\Models\LessonResource;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ScienceLessonResourceProvider implements LessonResourceFulfillmentProvider
{
    public function key(): string
    {
        return 'science_lesson_foundation';
    }

    public function strategy(): string
    {
        return 'curated_internal_or_authoritative_retrieval';
    }

    public function supports(LessonResource $resource): bool
    {
        return $resource->category === 'lesson_resource'
            && in_array(data_get($resource->metadata, 'science_foundation_asset'), [
                'coastal_change', 'process_cards', 'systems_map', 'water_cycle_diagram', 'covered_bowl_sheet',
                'evaporation_observation', 'coastal_weather_dataset', 'weather_cer',
            ], true);
    }

    public function fulfill(LessonResource $resource): FulfilledLessonResourceData
    {
        return match (data_get($resource->metadata, 'science_foundation_asset')) {
            'coastal_change' => $this->coastalChange(),
            'process_cards' => $this->instructionalGraphic('earth-process-sorting-cards.png', 'Earth Process Sorting Cards', [
                'Weathering — breaks rock into smaller pieces', 'Erosion — carries sediment to a new place',
                'Deposition — drops sediment when motion slows', 'Runoff — moving water over land',
                'Wind transport — moving air carries loose sediment', 'Moving ice — glaciers move rock and sediment',
                'Accumulation — layers of sediment build up', 'Compaction and cementation — press and bind sediment into rock',
            ]),
            'systems_map' => $this->instructionalGraphic('earth-process-systems-map.png', 'Earth Processes Systems Map', [
                'Choose at least five terms.', 'Build at least three cause-and-effect connections.',
                'Label each arrow with breaks, carries, drops, heats, cools, or builds up.',
                'Finish with one question you want to investigate.',
            ]),
            'water_cycle_diagram' => $this->instructionalGraphic('grade-5-water-cycle-model.png', 'Grade 5 Water-Cycle Model', [
                'The Sun supplies energy for evaporation from the ocean.',
                'Water vapor rises, cools, and condenses into cloud droplets.',
                'Precipitation returns water to land and water bodies.',
                'Runoff and rivers move water toward the ocean; some water infiltrates soil.',
                'Collection stores water in oceans and other reservoirs.',
            ]),
            'covered_bowl_sheet' => $this->instructionalGraphic('covered-bowl-investigation-guide.png', 'Covered-Bowl Water-Cycle Investigation', [
                'Adult: add about 250 mL of warm water to a clear bowl.',
                'Seal with plastic wrap and a rubber band; place 4–6 ice cubes on top.',
                'Observe the beginning model and again after 15–20 minutes.',
                'Connect visible evidence to evaporation, condensation, and falling droplets.',
                'State one limitation of this small model. Do not drink the water.',
            ]),
            'evaporation_observation' => $this->instructionalGraphic('evaporation-conditions-observation-guide.png', 'Evaporation Conditions Observation Guide', [
                'Use equal water drops on two matching saucers.',
                'Place one in a warmer or sunnier indoor location and one in cooler shade.',
                'Keep the water amount, saucers, and observation times the same.',
                'Compare both drops at the start, about 10 minutes, and about 20 minutes.',
                'Explain how the changed condition affected the rate of water loss.',
            ]),
            'coastal_weather_dataset' => $this->instructionalGraphic('two-day-coastal-weather-dataset.png', 'Two-Day Coastal Weather Instructional Dataset', [
                'Eight observations include air and water temperature, humidity, cloud cover, and precipitation.',
                'Day 1 noon: 29 C air, 26 C water, 58% humidity, 15% cloud cover, 0.0 mm precipitation.',
                'Day 2 noon: 28 C air, 28 C water, 82% humidity, 85% cloud cover, 0.0 mm precipitation.',
                'Day 2 8 p.m.: 24 C air, 27 C water, 90% humidity, 100% cloud cover, 5.8 mm precipitation.',
                'This short instructional dataset supports practice, not a universal weather rule.',
            ]),
            'weather_cer' => $this->instructionalGraphic('weather-cer-organizer.png', 'Weather Claim-Evidence-Reasoning Organizer', [
                'Claim: state a cause-and-effect idea supported by the dataset.',
                'Evidence 1: cite an exact time and measured value.',
                'Evidence 2: cite a second exact time and measured value.',
                'Reasoning: connect the measurements to water-cycle interactions.',
                'Limitation: state what two days of observations cannot prove.',
            ]),
            default => throw new RuntimeException('The Science lesson resource is not configured.'),
        };
    }

    private function coastalChange(): FulfilledLessonResourceData
    {
        $config = config('lesson-resources.providers.science_lesson_foundation.coastal_change');
        $before = Http::timeout(60)->get($config['before_url'])->throw()->body();
        $after = Http::timeout(60)->get($config['after_url'])->throw()->body();
        $beforeImage = @imagecreatefromstring($before);
        $afterImage = @imagecreatefromstring($after);
        if (! $beforeImage || ! $afterImage) {
            throw new RuntimeException('The authoritative coastal-change images could not be decoded.');
        }

        $canvas = imagecreatetruecolor(1600, 900);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $ink = imagecolorallocate($canvas, 22, 55, 71);
        imagefill($canvas, 0, 0, $white);
        imagestring($canvas, 5, 520, 20, 'Big Hickory Beach: Before and After Hurricane Ian', $ink);
        $this->copyCover($canvas, $beforeImage, 20, 70, 770, 760);
        $this->copyCover($canvas, $afterImage, 810, 70, 770, 760);
        imagefilledrectangle($canvas, 20, 830, 790, 880, $white);
        imagefilledrectangle($canvas, 810, 830, 1580, 880, $white);
        imagestring($canvas, 5, 350, 845, 'BEFORE', $ink);
        imagestring($canvas, 5, 1140, 845, 'AFTER', $ink);
        imagedestroy($beforeImage);
        imagedestroy($afterImage);
        ob_start();
        imagejpeg($canvas, null, 90);
        $contents = (string) ob_get_clean();
        imagedestroy($canvas);

        return new FulfilledLessonResourceData(
            contents: $contents,
            filename: 'usgs-big-hickory-beach-before-after.jpg',
            mimeType: 'image/jpeg',
            sourceUrl: $config['source_url'],
            sourceAttribution: $config['attribution'],
            licenseName: $config['license_name'],
            licenseUrl: $config['license_url'],
            providerMetadata: ['before_source_url' => $config['before_page'], 'after_source_url' => $config['after_page'], 'presentation' => 'side_by_side'],
        );
    }

    private function instructionalGraphic(string $filename, string $title, array $lines): FulfilledLessonResourceData
    {
        $canvas = imagecreatetruecolor(1600, 1000);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $ink = imagecolorallocate($canvas, 22, 55, 71);
        $teal = imagecolorallocate($canvas, 224, 243, 241);
        imagefill($canvas, 0, 0, $white);
        imagestring($canvas, 5, 55, 40, $title, $ink);
        foreach ($lines as $index => $line) {
            $y = 110 + ($index * (int) floor(820 / max(count($lines), 1)));
            imagefilledrectangle($canvas, 50, $y, 1550, $y + 75, $teal);
            imagestring($canvas, 5, 75, $y + 27, $line, $ink);
        }
        ob_start();
        imagepng($canvas);
        $contents = (string) ob_get_clean();
        imagedestroy($canvas);

        return new FulfilledLessonResourceData(
            contents: $contents,
            filename: $filename,
            mimeType: 'image/png',
            sourceUrl: rtrim((string) config('app.url'), '/').'/internal-instructional-assets',
            sourceAttribution: 'Deterministically generated by Learning-App from the approved lesson content',
            licenseName: 'Learning-App internal instructional asset',
            licenseUrl: rtrim((string) config('app.url'), '/').'/internal-instructional-assets',
            providerMetadata: ['rendering_version' => 1, 'structured_source' => 'lesson_activity_blueprint'],
        );
    }

    private function copyCover(\GdImage $canvas, \GdImage $source, int $x, int $y, int $width, int $height): void
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = max($width / $sourceWidth, $height / $sourceHeight);
        $cropWidth = (int) round($width / $scale);
        $cropHeight = (int) round($height / $scale);
        $sourceX = (int) max(0, ($sourceWidth - $cropWidth) / 2);
        $sourceY = (int) max(0, ($sourceHeight - $cropHeight) / 2);
        imagecopyresampled($canvas, $source, $x, $y, $sourceX, $sourceY, $width, $height, $cropWidth, $cropHeight);
    }
}
