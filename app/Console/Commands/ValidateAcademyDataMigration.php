<?php

namespace App\Console\Commands;

use App\Services\AcademyMigrationEnvironment;
use App\Services\AcademyMigrationManifest;
use App\Services\AcademyMigrationValidator;
use Illuminate\Console\Command;
use Throwable;

class ValidateAcademyDataMigration extends Command
{
    protected $signature = 'academy-data:validate';

    protected $description = 'Read-only validation of the MySQL-to-PostgreSQL academy data migration';

    public function handle(AcademyMigrationEnvironment $environment, AcademyMigrationValidator $validator): int
    {
        try {
            [$source, $target, $targetDatabase] = $environment->guardedConnections();
            $this->components->info("Read-only validation against PostgreSQL 16 database [{$targetDatabase}].");
            $result = $validator->validate($source, $target);
            $this->table(
                ['Key table', 'MySQL', 'PostgreSQL'],
                collect(AcademyMigrationManifest::KEY_TABLES)->map(fn ($table) => [
                    $table, $result['counts'][$table]['source'], $result['counts'][$table]['target'],
                ])
            );
            $this->table(
                ['Semantic metric', 'MySQL', 'PostgreSQL'],
                collect($result['metrics'])->map(fn ($counts, $name) => [$name, $counts['source'], $counts['target']])->values()
            );
            $this->info('All table counts, primary-key sets, foreign keys, sequences, and password hashes passed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
