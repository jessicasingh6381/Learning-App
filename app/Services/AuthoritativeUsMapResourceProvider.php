<?php

namespace App\Services;

use App\Contracts\LessonResourceFulfillmentProvider;
use App\Data\FulfilledLessonResourceData;
use App\Models\LessonResource;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AuthoritativeUsMapResourceProvider implements LessonResourceFulfillmentProvider
{
    public function key(): string
    {
        return 'usgs_maps';
    }

    public function strategy(): string
    {
        return 'authoritative_retrieval';
    }

    public function supports(LessonResource $resource): bool
    {
        return $resource->category === 'lesson_resource'
            && in_array($resource->resource_type, ['blank_map', 'reference_map'], true)
            && config("lesson-resources.providers.usgs_maps.resources.{$resource->resource_type}") !== null;
    }

    public function fulfill(LessonResource $resource): FulfilledLessonResourceData
    {
        $definition = config("lesson-resources.providers.usgs_maps.resources.{$resource->resource_type}");
        if (! is_array($definition)) {
            throw new RuntimeException('No vetted USGS map is configured for this resource.');
        }

        $response = Http::accept('application/pdf')->timeout(45)->get($definition['download_url']);
        if (! $response->successful()) {
            throw new RuntimeException("The authoritative map provider returned HTTP {$response->status()}.");
        }

        return new FulfilledLessonResourceData(
            contents: $response->body(),
            filename: $definition['filename'],
            mimeType: 'application/pdf',
            sourceUrl: $definition['product_url'],
            sourceAttribution: config('lesson-resources.providers.usgs_maps.attribution'),
            licenseName: config('lesson-resources.providers.usgs_maps.license_name'),
            licenseUrl: config('lesson-resources.providers.usgs_maps.license_url'),
            providerMetadata: [
                'provider_title' => $definition['title'],
                'product_number' => $definition['product_number'],
                'retrieval_url' => $definition['download_url'],
            ],
        );
    }
}
