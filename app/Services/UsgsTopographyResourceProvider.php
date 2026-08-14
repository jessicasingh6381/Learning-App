<?php

namespace App\Services;

use App\Contracts\LessonResourceFulfillmentProvider;
use App\Data\FulfilledLessonResourceData;
use App\Models\LessonResource;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class UsgsTopographyResourceProvider implements LessonResourceFulfillmentProvider
{
    public function key(): string { return 'usgs_topography'; }
    public function strategy(): string { return 'authoritative_retrieval'; }

    public function supports(LessonResource $resource): bool
    {
        return $resource->category === 'lesson_resource'
            && $resource->resource_type === 'physical_us_map'
            && $resource->delivery_type === 'viewable';
    }

    public function fulfill(LessonResource $resource): FulfilledLessonResourceData
    {
        $definition = config('lesson-resources.providers.usgs_topography');
        $response = Http::accept('image/jpeg')->timeout(60)->get($definition['download_url']);
        if (! $response->successful()) {
            throw new RuntimeException("The authoritative topography provider returned HTTP {$response->status()}.");
        }

        return new FulfilledLessonResourceData(
            contents: $response->body(), filename: $definition['filename'], mimeType: 'image/jpeg',
            sourceUrl: $definition['source_url'], sourceAttribution: $definition['attribution'],
            licenseName: $definition['license_name'], licenseUrl: $definition['license_url'],
            providerMetadata: [
                'provider_title' => $definition['dataset_title'],
                'dataset_date' => $definition['dataset_date'],
                'retrieval_url' => $definition['download_url'],
                'geographic_extent' => 'Contiguous United States',
                'instructional_use' => 'Physical relief comparison map',
            ],
        );
    }
}
