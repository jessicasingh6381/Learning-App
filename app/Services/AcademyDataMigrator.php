<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use Throwable;

final class AcademyDataMigrator
{
    public function __construct(private readonly AcademyMigrationValidator $validator) {}

    /** @return array{counts:array<string,int>, transformations:array<string,int>} */
    public function dryRun(ConnectionInterface $source, ConnectionInterface $target): array
    {
        $this->assertSchemasMatch($source, $target);
        $this->assertTargetEmpty($target);

        $counts = [];
        $transformations = [];
        foreach (AcademyMigrationManifest::TABLES as $table) {
            $types = $this->targetColumnTypes($target, $table);
            $counts[$table] = 0;
            foreach ($source->table($table)->orderBy('id')->cursor() as $row) {
                $this->transformRow($table, (array) $row, $types, $transformations);
                $counts[$table]++;
            }
        }

        Log::info('Academy migration dry run passed', [
            'tables' => count($counts), 'rows' => array_sum($counts), 'transformations' => $transformations,
        ]);

        return compact('counts', 'transformations');
    }

    /** @return array{counts:array<string,int>, transformations:array<string,int>} */
    public function migrate(ConnectionInterface $source, ConnectionInterface $target, int $chunkSize): array
    {
        $this->assertSchemasMatch($source, $target);
        $this->assertTargetEmpty($target);
        $counts = [];
        $transformations = [];
        $deferred = [];

        $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $source->beginTransaction();
        $target->beginTransaction();

        try {
            foreach (AcademyMigrationManifest::TABLES as $table) {
                $types = $this->targetColumnTypes($target, $table);
                $counts[$table] = 0;
                $buffer = [];

                foreach ($source->table($table)->orderBy('id')->cursor() as $rowObject) {
                    $row = $this->transformRow($table, (array) $rowObject, $types, $transformations);
                    foreach (AcademyMigrationManifest::DEFERRED_SELF_REFERENCES[$table] ?? [] as $column) {
                        if (($row[$column] ?? null) !== null) {
                            $deferred[$table][] = ['id' => $row['id'], 'column' => $column, 'value' => $row[$column]];
                            $row[$column] = null;
                        }
                    }
                    $buffer[] = $row;
                    $counts[$table]++;
                    if (count($buffer) >= $chunkSize) {
                        $target->table($table)->insert($buffer);
                        $buffer = [];
                    }
                }
                if ($buffer !== []) {
                    $target->table($table)->insert($buffer);
                }
                Log::info('Academy migration table copied', ['table' => $table, 'rows' => $counts[$table]]);
            }

            foreach ($deferred as $table => $updates) {
                foreach ($updates as $update) {
                    $target->table($table)->where('id', $update['id'])->update([$update['column'] => $update['value']]);
                }
            }

            $this->resetSequences($target);
            $this->validator->validate($source, $target);
            $source->commit();
            $target->commit();
        } catch (Throwable $exception) {
            if ($target->transactionLevel() > 0) {
                $target->rollBack();
            }
            if ($source->transactionLevel() > 0) {
                $source->rollBack();
            }
            Log::error('Academy migration rolled back', ['exception' => $exception::class, 'message' => $exception->getMessage()]);
            throw $exception;
        }

        Log::info('Academy migration completed', [
            'tables' => count($counts), 'rows' => array_sum($counts), 'transformations' => $transformations,
        ]);

        return compact('counts', 'transformations');
    }

    private function assertSchemasMatch(ConnectionInterface $source, ConnectionInterface $target): void
    {
        if (($errors = $this->validator->schemaErrors($source, $target)) !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
    }

    private function assertTargetEmpty(ConnectionInterface $target): void
    {
        $nonEmpty = array_filter($this->validator->counts($target));
        if ($nonEmpty !== []) {
            throw new RuntimeException(
                'Refusing to continue: target application tables are not empty: '.json_encode($nonEmpty, JSON_THROW_ON_ERROR)
            );
        }
    }

    /** @return array<string,string> */
    private function targetColumnTypes(ConnectionInterface $target, string $table): array
    {
        return collect($target->select(
            'SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = ?',
            ['public', $table]
        ))->mapWithKeys(fn (object $row) => [$row->column_name => $row->data_type])->all();
    }

    /** @param array<string,mixed> $row @param array<string,string> $types @param array<string,int> $transformations @return array<string,mixed> */
    private function transformRow(string $table, array $row, array $types, array &$transformations): array
    {
        foreach ($row as $column => $value) {
            if ($value === null) {
                continue;
            }
            $type = $types[$column] ?? null;
            if ($type === 'boolean') {
                if (! in_array($value, [0, 1, '0', '1', false, true], true)) {
                    throw new RuntimeException("Invalid boolean in {$table}.{$column} for row ID ".($row['id'] ?? '?').'.');
                }
                $row[$column] = (bool) $value;
                $transformations['mysql_boolean_to_postgresql_boolean'] = ($transformations['mysql_boolean_to_postgresql_boolean'] ?? 0) + 1;
            } elseif (in_array($type, ['json', 'jsonb'], true)) {
                $row[$column] = $this->normalizeJson($value, $table, $column, $row['id'] ?? null);
                $transformations['json_validated'] = ($transformations['json_validated'] ?? 0) + 1;
            } elseif (
                in_array($type, ['date', 'timestamp without time zone', 'timestamp with time zone'], true)
                && is_string($value) && str_starts_with($value, '0000-00-00')
            ) {
                throw new RuntimeException("Unsupported zero date in {$table}.{$column} for row ID ".($row['id'] ?? '?').'.');
            }
        }

        return $row;
    }

    private function normalizeJson(mixed $value, string $table, string $column, mixed $id): string
    {
        try {
            if (is_string($value)) {
                json_decode($value, true, 512, JSON_THROW_ON_ERROR);

                return $value;
            }

            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid JSON in {$table}.{$column} for row ID ".($id ?? '?').'.', 0, $exception);
        }
    }

    private function resetSequences(ConnectionInterface $target): void
    {
        foreach (AcademyMigrationManifest::TABLES as $table) {
            if (! in_array('id', $target->getSchemaBuilder()->getColumnListing($table), true)) {
                continue;
            }
            $sequence = $target->selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", [$table])->name ?? null;
            if (! $sequence) {
                continue;
            }
            $max = $target->table($table)->max('id');
            $target->select('SELECT setval(CAST(? AS regclass), ?, ?)', [$sequence, $max ?? 1, $max !== null]);
        }
    }
}
