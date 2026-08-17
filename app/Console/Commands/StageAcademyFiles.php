<?php

namespace App\Console\Commands;

use App\Services\AcademyMigrationEnvironment;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class StageAcademyFiles extends Command
{
    protected $signature = 'academy-files:stage
        {destination : An existing staging directory outside the source disk}
        {--execute : Copy files; omission only inventories and verifies}
        {--confirm-destination= : Exact resolved destination path required when executing}';

    protected $description = 'Inventory or stage database-referenced private academy files without changing database records';

    public function handle(): int
    {
        try {
            $source = DB::connection(AcademyMigrationEnvironment::SOURCE);
            if ($source->getDriverName() !== 'mysql' || ! $source->getDatabaseName()) {
                throw new RuntimeException('migration_source must be an explicitly configured MySQL database.');
            }
            $sourceRoot = realpath((string) config('filesystems.disks.local.root'));
            $destination = realpath((string) $this->argument('destination'));
            if ($sourceRoot === false || $destination === false || ! is_dir($destination)) {
                throw new RuntimeException('The source disk and destination must be existing directories.');
            }
            if ($this->overlaps($sourceRoot, $destination)) {
                throw new RuntimeException('Destination must be outside the local source disk.');
            }
            if ($this->option('execute') && ! hash_equals($destination, (string) $this->option('confirm-destination'))) {
                throw new RuntimeException("Execution requires --confirm-destination=\"{$destination}\".");
            }

            $files = $this->referencedFiles($source);
            $missing = [];
            $copied = 0;
            $unchanged = 0;
            $bytes = 0;
            foreach ($files as $path => $metadata) {
                $relative = $this->safeRelativePath($path);
                $sourcePath = $sourceRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (! is_file($sourcePath)) {
                    $missing[] = $relative;

                    continue;
                }
                $actualHash = hash_file('sha256', $sourcePath);
                if ($metadata['checksum'] && ! hash_equals(strtolower($metadata['checksum']), strtolower($actualHash))) {
                    throw new RuntimeException("Checksum mismatch for referenced source file [{$relative}].");
                }
                $bytes += filesize($sourcePath);

                if (! $this->option('execute')) {
                    continue;
                }
                $targetPath = $destination.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (is_file($targetPath)) {
                    if (hash_equals($actualHash, hash_file('sha256', $targetPath))) {
                        $unchanged++;

                        continue;
                    }
                    throw new RuntimeException("Refusing to overwrite different destination file [{$relative}].");
                }
                File::ensureDirectoryExists(dirname($targetPath));
                if (! copy($sourcePath, $targetPath) || ! hash_equals($actualHash, hash_file('sha256', $targetPath))) {
                    throw new RuntimeException("Copy verification failed for [{$relative}].");
                }
                $copied++;
            }
            if ($missing !== []) {
                throw new RuntimeException(count($missing).' referenced files are missing. First paths: '.implode(', ', array_slice($missing, 0, 5)));
            }

            $mode = $this->option('execute') ? 'Staging completed' : 'DRY RUN passed; no files copied';
            $this->info("{$mode}. Files: ".count($files).'; bytes: '.$bytes."; copied: {$copied}; unchanged: {$unchanged}.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string,array{checksum:?string,kind:string}> */
    private function referencedFiles(ConnectionInterface $source): array
    {
        $files = [];
        $unsupportedSourceDisks = $source->table('academic_source_files')->where('disk', '<>', 'local')->distinct()->pluck('disk');
        $unsupportedAssetDisks = $source->table('lesson_resources')->whereNotNull('asset_path')
            ->where('asset_disk', '<>', 'local')->distinct()->pluck('asset_disk');
        if ($unsupportedSourceDisks->concat($unsupportedAssetDisks)->filter()->isNotEmpty()) {
            throw new RuntimeException('The file stager only supports records on the local disk.');
        }
        foreach ($source->table('academic_source_files')->where('disk', 'local')->get(['stored_path', 'checksum_sha256']) as $row) {
            $files[$row->stored_path] = ['checksum' => $row->checksum_sha256, 'kind' => 'academic source'];
        }
        foreach ($source->table('lesson_resources')->where('asset_disk', 'local')->whereNotNull('asset_path')
            ->get(['asset_path', 'checksum_sha256']) as $row) {
            $existing = $files[$row->asset_path]['checksum'] ?? null;
            if ($existing && $row->checksum_sha256 && ! hash_equals(strtolower($existing), strtolower($row->checksum_sha256))) {
                throw new RuntimeException("Conflicting database checksums for path [{$row->asset_path}].");
            }
            $files[$row->asset_path] = ['checksum' => $row->checksum_sha256 ?: $existing, 'kind' => 'lesson resource'];
        }

        return $files;
    }

    private function safeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)
            || in_array('..', explode('/', $path), true)) {
            throw new RuntimeException("Unsafe stored file path [{$path}].");
        }

        return $path;
    }

    private function overlaps(string $source, string $destination): bool
    {
        $source = strtolower(rtrim($source, '\\/')).DIRECTORY_SEPARATOR;
        $destination = strtolower(rtrim($destination, '\\/')).DIRECTORY_SEPARATOR;

        return str_starts_with($source, $destination) || str_starts_with($destination, $source);
    }
}
