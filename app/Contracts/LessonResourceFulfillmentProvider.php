<?php

namespace App\Contracts;

use App\Data\FulfilledLessonResourceData;
use App\Models\LessonResource;

interface LessonResourceFulfillmentProvider
{
    public function key(): string;

    public function strategy(): string;

    public function supports(LessonResource $resource): bool;

    public function fulfill(LessonResource $resource): FulfilledLessonResourceData;
}
