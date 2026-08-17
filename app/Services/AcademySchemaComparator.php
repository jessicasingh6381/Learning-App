<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;

final class AcademySchemaComparator
{
    /** MySQL/MariaDB supplies an implicit CURRENT_TIMESTAMP for these required timestamp columns. */
    private const IMPLICIT_MYSQL_TIMESTAMP_DEFAULTS = [
        'academic_source_files.uploaded_at',
        'curriculum_parser_capabilities.assessed_at',
        'creative_writing_entries.assigned_at',
    ];

    /** @return list<string> */
    public function compare(ConnectionInterface $source, ConnectionInterface $target, string $table): array
    {
        return $this->compareDefinitions(
            $this->mysqlColumns($source, $table),
            $this->postgresColumns($target, $table),
            $table
        );
    }

    /**
     * Column order is intentionally ignored. Laravel's after() placement is supported by MySQL but ignored by
     * PostgreSQL; inserts are associative and therefore do not depend on physical catalog order.
     *
     * @param  list<array<string, mixed>>  $sourceColumns
     * @param  list<array<string, mixed>>  $targetColumns
     * @return list<string>
     */
    public function compareDefinitions(array $sourceColumns, array $targetColumns, string $table): array
    {
        $errors = [];
        $source = collect($sourceColumns)->keyBy('column_name');
        $target = collect($targetColumns)->keyBy('column_name');
        if ($source->isEmpty()) {
            $errors[] = "Source table {$table} has no discoverable columns.";
        }
        if ($target->isEmpty()) {
            $errors[] = "Target table {$table} has no discoverable columns.";
        }

        foreach ($source->keys()->diff($target->keys()) as $column) {
            $errors[] = "Target table {$table} is missing application column {$column}.";
        }
        foreach ($target->keys()->diff($source->keys()) as $column) {
            $errors[] = "Target table {$table} has unexpected application column {$column}.";
        }

        foreach ($source->keys()->intersect($target->keys()) as $name) {
            $sourceColumn = $source[$name];
            $targetColumn = $target[$name];
            if (! $this->typesAreCompatible($sourceColumn, $targetColumn)) {
                $errors[] = "Incompatible type for {$table}.{$name}: MySQL {$sourceColumn['column_type']}, PostgreSQL {$targetColumn['data_type']}.";

                continue;
            }
            if (($sourceColumn['is_nullable'] === 'YES') !== ($targetColumn['is_nullable'] === 'YES')) {
                $errors[] = "Nullability mismatch for {$table}.{$name}.";
            }

            $sourceAuto = str_contains(strtolower((string) $sourceColumn['extra']), 'auto_increment');
            $targetAuto = $targetColumn['is_identity'] === 'YES'
                || str_starts_with(strtolower((string) $targetColumn['column_default']), 'nextval(');
            if ($sourceAuto !== $targetAuto) {
                $errors[] = "Auto-increment/sequence mismatch for {$table}.{$name}.";
            }

            $sourceGenerated = trim((string) $sourceColumn['generation_expression']) !== '';
            $targetGenerated = $targetColumn['is_generated'] !== 'NEVER'
                || trim((string) $targetColumn['generation_expression']) !== '';
            if ($sourceGenerated !== $targetGenerated) {
                $errors[] = "Generated-column mismatch for {$table}.{$name}.";
            } elseif ($sourceGenerated && $targetGenerated) {
                $errors[] = "Generated column {$table}.{$name} requires an explicit cross-engine review.";
            }

            $sourceDefault = $this->normalizeDefault($sourceColumn['column_default'], $sourceColumn, $targetColumn);
            $targetDefault = $this->normalizeDefault($targetColumn['column_default'], $sourceColumn, $targetColumn);
            $knownImplicitTimestamp = in_array("{$table}.{$name}", self::IMPLICIT_MYSQL_TIMESTAMP_DEFAULTS, true)
                && $sourceDefault === 'current_timestamp' && $targetDefault === null;
            if (! $sourceAuto && $sourceDefault !== $targetDefault && ! $knownImplicitTimestamp) {
                $errors[] = "Default mismatch for {$table}.{$name}: MySQL [{$sourceColumn['column_default']}], PostgreSQL [{$targetColumn['column_default']}].";
            }
        }

        return $errors;
    }

    /** @return list<array<string,mixed>> */
    private function mysqlColumns(ConnectionInterface $source, string $table): array
    {
        return array_map(fn (object $column) => (array) $column, $source->select(<<<'SQL'
            SELECT column_name, data_type, column_type, is_nullable, column_default, extra,
                   generation_expression, character_maximum_length, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ?
            ORDER BY ordinal_position
            SQL, [$table]));
    }

    /** @return list<array<string,mixed>> */
    private function postgresColumns(ConnectionInterface $target, string $table): array
    {
        return array_map(fn (object $column) => (array) $column, $target->select(<<<'SQL'
            SELECT column_name, data_type, is_nullable, column_default, is_identity, is_generated,
                   generation_expression, character_maximum_length, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ?
            ORDER BY ordinal_position
            SQL, ['public', $table]));
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $target */
    private function typesAreCompatible(array $source, array $target): bool
    {
        $sourceType = strtolower((string) $source['data_type']);
        $targetType = strtolower((string) $target['data_type']);

        if ($sourceType === 'tinyint' && preg_match('/^tinyint\(1\)(?: unsigned)?$/', strtolower($source['column_type']))) {
            return $targetType === 'boolean';
        }
        if ($sourceType === 'longtext' && $targetType === 'json') {
            return true;
        }
        if ($sourceType === 'char' && (int) $source['character_maximum_length'] === 36 && $targetType === 'uuid') {
            return true;
        }

        $sourceType = match ($sourceType) {
            'int' => 'integer',
            'decimal' => 'numeric',
            'varchar' => 'character varying',
            'char' => 'character',
            'datetime', 'timestamp' => 'timestamp',
            'longtext', 'mediumtext' => 'text',
            default => $sourceType,
        };
        $targetType = match ($targetType) {
            'timestamp without time zone' => 'timestamp',
            default => $targetType,
        };

        if ($sourceType !== $targetType) {
            return false;
        }
        if (in_array($sourceType, ['character varying', 'character'], true)) {
            return (int) $source['character_maximum_length'] === (int) $target['character_maximum_length'];
        }
        if ($sourceType === 'numeric') {
            return (int) $source['numeric_precision'] === (int) $target['numeric_precision']
                && (int) $source['numeric_scale'] === (int) $target['numeric_scale'];
        }

        return true;
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $target */
    private function normalizeDefault(mixed $default, array $source, array $target): ?string
    {
        if ($default === null || strtoupper(trim((string) $default)) === 'NULL') {
            return null;
        }

        $value = trim((string) $default);
        $value = preg_replace('/::(?:[a-z_ ]+|"[^"]+")$/i', '', $value) ?? $value;
        if (preg_match("/^'(.*)'$/s", $value, $matches)) {
            $value = str_replace("''", "'", $matches[1]);
        }

        $isBoolean = strtolower((string) $target['data_type']) === 'boolean'
            || (strtolower((string) $source['data_type']) === 'tinyint'
                && preg_match('/^tinyint\(1\)/', strtolower((string) $source['column_type'])));
        if ($isBoolean) {
            return in_array(strtolower($value), ['1', 'true', 't'], true) ? 'true' : 'false';
        }
        if (in_array(strtolower($value), ['current_timestamp', 'current_timestamp()', 'now()'], true)) {
            return 'current_timestamp';
        }

        return $value;
    }
}
