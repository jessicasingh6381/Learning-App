<?php

namespace App\Services;

use App\Contracts\PdfTextExtractor;
use App\Models\AcademicSourceFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class CurriculumSourcePdfExtractor
{
    public function __construct(private PdfTextExtractor $extractor) {}

    public function extract(AcademicSourceFile $file): array
    {
        $disk = Storage::disk($file->disk);
        $temporary = null;
        try {
            try { $path = $disk->path($file->stored_path); }
            catch (Throwable) {
                $temporary = tempnam(sys_get_temp_dir(), 'curriculum-pdf-');
                if ($temporary === false) throw new RuntimeException('The PDF could not be read. Confirm that it is a valid, unencrypted PDF.');
                $source = $disk->readStream($file->stored_path); $target = fopen($temporary, 'wb');
                if ($source === false || $target === false) throw new RuntimeException('The PDF could not be read. Confirm that it is a valid, unencrypted PDF.');
                try { stream_copy_to_stream($source, $target); } finally { if (is_resource($source)) fclose($source); if (is_resource($target)) fclose($target); }
                $path = $temporary;
            }

            return $this->extractor->extract($path);
        } finally {
            if ($temporary && is_file($temporary)) @unlink($temporary);
        }
    }
}
