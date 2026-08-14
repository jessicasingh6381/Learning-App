<?php

namespace App\Services;

use App\Contracts\LessonResourceFulfillmentProvider;
use App\Models\Lesson;
use App\Models\LessonResource;
use Illuminate\Support\Facades\Storage;
use Throwable;

class LessonResourceFulfillmentManager
{
    /** @var list<LessonResourceFulfillmentProvider> */
    private array $providers;

    public function __construct(
        AuthoritativeUsMapResourceProvider $maps,
        CensusStateGeometryResourceProvider $stateGeometry,
        UsgsTopographyResourceProvider $topography,
        CensusPopulationDensityResourceProvider $populationDensity,
        ScienceLessonResourceProvider $scienceLessons,
        MathLessonResourceProvider $mathLessons,
        ElarLessonResourceProvider $elarLessons,
        TechnologyLessonResourceProvider $technologyLessons,
        SpanishLessonResourceProvider $spanishLessons,
        private readonly LessonResourceAssetValidator $validator,
    ) {
        $this->providers = [$spanishLessons, $technologyLessons, $elarLessons, $mathLessons, $scienceLessons, $stateGeometry, $populationDensity, $topography, $maps];
    }

    public function fulfillRequiredForLesson(Lesson $lesson): array
    {
        return $lesson->resources()->where('category', 'lesson_resource')
            ->whereIn('availability_status', ['needs_asset', 'unavailable'])
            ->orderBy('sort_order')->get()
            ->map(fn (LessonResource $resource) => $this->fulfill($resource))
            ->all();
    }

    public function fulfill(LessonResource $resource): LessonResource
    {
        if ($resource->isAvailable()) {
            return $resource;
        }

        $provider = collect($this->providers)->first(fn ($candidate) => $candidate->supports($resource));
        if (! $provider) {
            return $resource;
        }

        $resource->update([
            'fulfillment_strategy' => $provider->strategy(),
            'fulfillment_provider' => $provider->key(),
            'fulfillment_attempted_at' => now(),
            'fulfillment_error' => null,
        ]);

        try {
            $previousDisk = $resource->asset_disk;
            $previousPath = $resource->asset_path;
            $asset = $provider->fulfill($resource);
            $validation = $this->validator->validate($asset);
            $checksum = hash('sha256', $asset->contents);
            $disk = (string) config('lesson-resources.disk');
            $extension = strtolower(pathinfo($asset->filename, PATHINFO_EXTENSION));
            if (! in_array($extension, ['pdf', 'geojson', 'json', 'jpg', 'jpeg', 'png'], true)) {
                throw new \RuntimeException('The validated resource has an unsupported file extension.');
            }
            $path = "lesson-resources/{$resource->tenant_id}/{$resource->lesson_id}/{$resource->id}-{$checksum}.{$extension}";
            if (! Storage::disk($disk)->put($path, $asset->contents)) {
                throw new \RuntimeException('The validated resource could not be stored.');
            }
            if (! Storage::disk($disk)->exists($path) || Storage::disk($disk)->size($path) !== strlen($asset->contents)) {
                Storage::disk($disk)->delete($path);
                throw new \RuntimeException('The stored resource did not pass integrity verification.');
            }

            $resource->update([
                'availability_status' => 'ready',
                'asset_disk' => $disk,
                'asset_path' => $path,
                'original_filename' => $asset->filename,
                'mime_type' => $asset->mimeType,
                'checksum_sha256' => $checksum,
                'file_size' => strlen($asset->contents),
                'generated_by' => $provider->key(),
                'generated_at' => now(),
                'source_url' => $asset->sourceUrl,
                'source_attribution' => $asset->sourceAttribution,
                'license_name' => $asset->licenseName,
                'license_url' => $asset->licenseUrl,
                'metadata' => [...($resource->metadata ?? []), ...$asset->providerMetadata],
                'validation_metadata' => $validation,
                'fulfillment_error' => null,
            ]);
            $expectedPrefix = "lesson-resources/{$resource->tenant_id}/{$resource->lesson_id}/";
            if ($previousDisk && $previousPath && $previousPath !== $path && str_starts_with($previousPath, $expectedPrefix)) {
                Storage::disk($previousDisk)->delete($previousPath);
            }
        } catch (Throwable $exception) {
            $resource->update([
                'availability_status' => 'unavailable',
                'fulfillment_error' => mb_substr($exception->getMessage(), 0, 500),
            ]);
        }

        return $resource->fresh();
    }
}
