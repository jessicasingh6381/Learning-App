<?php

namespace App\Services;

use App\Contracts\PdfTextExtractor;
use RuntimeException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

final class SmalotPdfTextExtractor implements PdfTextExtractor
{
    public function extract(string $absolutePath): array
    {
        try {
            $config = new Config;
            $config->setDataTmFontInfoHasToBeIncluded(true);
            $pages = (new Parser([], $config))->parseFile($absolutePath)->getPages();
        } catch (Throwable $exception) {
            throw new RuntimeException('The PDF could not be read. Confirm that it is a valid, unencrypted PDF.', previous: $exception);
        }

        return collect($pages)->values()->map(fn ($page, int $index) => [
            'page' => $index + 1,
            'text' => trim($page->getText()),
            'items' => collect($page->getDataTm())->map(fn (array $item) => [
                'text' => trim((string) ($item[1] ?? '')),
                'x' => (float) ($item[0][4] ?? 0),
                'y' => (float) ($item[0][5] ?? 0),
                'width' => abs((float) ($item[0][0] ?? 0)),
                'height' => abs((float) ($item[0][3] ?? 0)),
            ])->filter(fn (array $item) => $item['text'] !== '')->values()->all(),
        ])->all();
    }
}
