<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class AcademicSourceUpload implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Upload a valid academic source file.');

            return;
        }

        $filename = $value->getClientOriginalName();
        $extension = strtolower($value->getClientOriginalExtension());
        $allowed = (array) config('academic_sources.extensions', []);
        $maxBytes = (int) config('academic_sources.max_upload_kilobytes', 25600) * 1024;

        if ($filename !== basename($filename) || ! isset($allowed[$extension])) {
            $fail('That file type is not allowed.');

            return;
        }

        if (($value->getSize() ?: 0) > $maxBytes) {
            $fail('The file may not be larger than 25 MB.');

            return;
        }

        $clientMime = strtolower((string) $value->getClientMimeType());
        $detectedMime = strtolower((string) $value->getMimeType());
        $allowedMimes = $allowed[$extension];

        if (! in_array($clientMime, $allowedMimes, true) || ! in_array($detectedMime, $allowedMimes, true)) {
            $fail('The file content does not match its allowed extension.');
        }
    }
}
