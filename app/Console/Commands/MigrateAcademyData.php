<?php

namespace App\Console\Commands;

use App\Services\AcademyDataMigrator;
use App\Services\AcademyMigrationEnvironment;
use App\Services\AcademyMigrationManifest;
use App\Services\AcademyMigrationValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class MigrateAcademyData extends Command
{
    protected $signature = 'academy-data:migrate
        {--execute : Perform the write transaction; omission is always a dry run}
        {--confirm-target= : Must exactly match the configured target database when executing}
        {--chunk=250 : Number of rows per PostgreSQL insert}';

    protected $description = 'Safely copy academy application data from the configured MySQL source to PostgreSQL 16';

    public function handle(
        AcademyMigrationEnvironment $environment,
        AcademyDataMigrator $migrator,
        AcademyMigrationValidator $validator
    ): int {
        try {
            [$source, $target, $targetDatabase] = $environment->guardedConnections();
            $execute = (bool) $this->option('execute');
            $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
            if ($chunk === false) {
                $this->error('--chunk must be an integer between 1 and 1000.');

                return self::INVALID;
            }
            if ($execute && ! hash_equals($targetDatabase, (string) $this->option('confirm-target'))) {
                $this->error("Execution requires --confirm-target={$targetDatabase}.");

                return self::FAILURE;
            }

            $this->components->info($execute ? 'EXECUTION mode: target writes are enabled.' : 'DRY RUN: no database writes will occur.');
            $this->line("Source: mysql / {$source->getDatabaseName()}");
            $this->line("Target: pgsql 16 / {$targetDatabase}");
            $this->line('Excluded runtime tables: '.implode(', ', AcademyMigrationManifest::EXCLUDED_RUNTIME_TABLES));
            $sourceCounts = $validator->counts($source);
            $targetCounts = $validator->counts($target);
            $this->table(
                ['Preflight table', 'MySQL source', 'PostgreSQL target'],
                collect(AcademyMigrationManifest::TABLES)->map(fn ($table) => [
                    $table, $sourceCounts[$table], $targetCounts[$table],
                ])
            );

            $result = $execute
                ? $migrator->migrate($source, $target, $chunk)
                : $migrator->dryRun($source, $target);

            $this->newLine();
            $this->info(($execute ? 'Migration and in-transaction validation passed' : 'Dry run passed').'. Total rows: '.array_sum($result['counts']));
            $this->line('Rows skipped: 0.');
            foreach ($result['transformations'] as $name => $count) {
                $this->line("Transformation {$name}: {$count}");
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Academy migration command failed', ['exception' => $exception::class, 'message' => $exception->getMessage()]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
