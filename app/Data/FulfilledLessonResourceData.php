<?php

namespace App\Data;

final readonly class FulfilledLessonResourceData
{
    public function __construct(
        public string $contents,
        public string $filename,
        public string $mimeType,
        public string $sourceUrl,
        public string $sourceAttribution,
        public string $licenseName,
        public string $licenseUrl,
        public array $providerMetadata = [],
    ) {}
}
