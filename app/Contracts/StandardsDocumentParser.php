<?php

namespace App\Contracts;

interface StandardsDocumentParser extends CurriculumOutlineParser
{
    public function importType(): string;
    public function matchingSections(array $pages, \App\Models\AcademicSource $source): array;
}
