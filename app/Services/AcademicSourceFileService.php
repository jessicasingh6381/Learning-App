<?php

namespace App\Services;

use App\Models\AcademicSource;
use App\Models\AcademicSourceFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AcademicSourceFileService
{
    public function store(AcademicSource $source, UploadedFile $upload, AuditService $audit): AcademicSourceFile
    {
        $disk = (string) config('academic_sources.disk', 'local');
        $extension = strtolower($upload->getClientOriginalExtension());
        $storedFilename = Str::uuid().'.'.$extension;
        $storedPath = 'academic-sources/'.$source->tenant_id.'/'.$source->uuid.'/'.$storedFilename;
        $checksum = hash_file('sha256', $upload->getRealPath());

        if ($checksum === false) {
            throw new RuntimeException('Unable to checksum the uploaded file.');
        }

        if (! Storage::disk($disk)->putFileAs(dirname($storedPath), $upload, $storedFilename)) {
            throw new RuntimeException('Unable to store the academic source file.');
        }

        try {
            return DB::transaction(function () use ($source, $upload, $audit, $disk, $storedFilename, $storedPath, $checksum) {
                $lockedSource = AcademicSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
                $nextVersion = ((int) $lockedSource->files()->withoutGlobalScopes()->max('version_number')) + 1;
                $lockedSource->files()->withoutGlobalScopes()->where('is_current', true)->update([
                    'is_current' => false,
                    'current_key' => null,
                ]);

                $file = $lockedSource->files()->create([
                    'uploaded_by_user_id' => auth()->id(),
                    'version_number' => $nextVersion,
                    'current_key' => 'current',
                    'is_current' => true,
                    'disk' => $disk,
                    'stored_path' => $storedPath,
                    'stored_filename' => $storedFilename,
                    'original_filename' => basename($upload->getClientOriginalName()),
                    'mime_type' => (string) $upload->getMimeType(),
                    'extension' => strtolower($upload->getClientOriginalExtension()),
                    'file_size' => (int) $upload->getSize(),
                    'checksum_sha256' => $checksum,
                    'uploaded_at' => now(),
                ]);
                $audit->record(
                    $nextVersion === 1 ? 'academic-source.file-uploaded' : 'academic-source.file-replaced',
                    $file,
                    [],
                    $file->toArray(),
                );

                return $file;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($storedPath);

            throw $exception;
        }
    }
}
