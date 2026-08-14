<?php

namespace App\Data;

final readonly class GeneratedLessonResourceData
{
    public function __construct(
        public string $category,
        public string $resourceType,
        public string $title,
        public ?string $description,
        public string $deliveryType,
        public int $sortOrder,
        public array $metadata = [],
    ) {}
}
