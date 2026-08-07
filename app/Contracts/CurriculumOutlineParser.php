<?php

namespace App\Contracts;

use App\Data\CurriculumParserResult;
use App\Data\CurriculumParserApplicability;
use App\Models\AcademicSource;

interface CurriculumOutlineParser
{
    public function supports(array $pages, AcademicSource $source): bool;
    public function recognitionScore(array $pages, AcademicSource $source): float;
    public function applicability(): CurriculumParserApplicability;
    public function parse(array $pages, AcademicSource $source): CurriculumParserResult;
    public function key(): string;
    public function version(): string;
    public function extractionMethod(): string;
}
