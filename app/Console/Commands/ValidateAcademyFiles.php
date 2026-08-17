<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ValidateAcademyFiles extends Command
{
    protected $signature = 'academy-files:validate';

    protected $description = 'Read-only validation of all database-referenced academy files on the active database and disks';

    public function handle(): int
    {
        try {
            $checked = 0;
            $bytes = 0;
            $rows = DB::table('academic_source_files')->get([
                'id', 'disk', 'stored_path AS path', 'checksum_sha256 AS checksum',
            ])->concat(DB::table('lesson_resources')->whereNotNull('asset_path')->get([
                'id', 'asset_disk AS disk', 'asset_path AS path', 'checksum_sha256 AS checksum',
            ]));

            foreach ($rows as $row) {
                if (! $row->disk || ! Storage::disk($row->disk)->exists($row->path)) {
                    throw new RuntimeException("Missing referenced file for record {$row->id} at [{$row->disk}:{$row->path}].");
                }
                $stream = Storage::disk($row->disk)->readStream($row->path);
                if (! is_resource($stream)) {
                    throw new RuntimeException("Could not read [{$row->disk}:{$row->path}].");
                }
                $context = hash_init('sha256');
                hash_update_stream($context, $stream);
                fclose($stream);
                $actual = hash_final($context);
                if ($row->checksum && ! hash_equals(strtolower($row->checksum), strtolower($actual))) {
                    throw new RuntimeException("Checksum mismatch for [{$row->disk}:{$row->path}].");
                }
                $bytes += Storage::disk($row->disk)->size($row->path);
                $checked++;
            }

            $this->info("Validated {$checked} database references ({$bytes} bytes); all files exist and checksums match where recorded.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
