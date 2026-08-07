<?php

namespace App\Contracts;

interface PdfTextExtractor
{
    /** @return array<int, array{page: int, text: string, items?: array<int, array{text: string, x: float, y: float, width: float, height: float}>}> */
    public function extract(string $absolutePath): array;
}
