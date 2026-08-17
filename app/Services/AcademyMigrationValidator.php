<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final class AcademyMigrationValidator
{
    /** @return array{counts: array<string, array{source:int,target:int}>, metrics: array<string, array{source:int,target:int}>, orphans: array<string,int>} */
    public function validate(ConnectionInterface $source, ConnectionInterface $target): array
    {
        $counts = [];
        foreach (AcademyMigrationManifest::TABLES as $table) {
            $sourceCount = $source->table($table)->count();
            $targetCount = $target->table($table)->count();
            $counts[$table] = ['source' => $sourceCount, 'target' => $targetCount];
            if ($sourceCount !== $targetCount) {
                throw new RuntimeException("Row-count mismatch for {$table}: source={$sourceCount}, target={$targetCount}.");
            }
            if ($this->hasColumn($target, $table, 'id') && $this->idDigest($source, $table) !== $this->idDigest($target, $table)) {
                throw new RuntimeException("Primary-key set mismatch for {$table}.");
            }
        }

        $metrics = [];
        $sourceMetrics = $this->semanticMetrics($source);
        $targetMetrics = $this->semanticMetrics($target);
        foreach ($sourceMetrics as $name => $sourceValue) {
            $targetValue = $targetMetrics[$name];
            $metrics[$name] = ['source' => $sourceValue, 'target' => $targetValue];
            if ($sourceValue !== $targetValue) {
                throw new RuntimeException("Semantic count mismatch for {$name}: source={$sourceValue}, target={$targetValue}.");
            }
        }

        $orphans = $this->orphanCounts($target);
        if (($bad = array_filter($orphans)) !== []) {
            throw new RuntimeException('Foreign-key orphan validation failed: '.json_encode($bad, JSON_THROW_ON_ERROR));
        }
        if (($errors = $this->sequenceErrors($target)) !== []) {
            throw new RuntimeException('PostgreSQL sequence validation failed: '.implode('; ', $errors));
        }
        if (($errors = $this->passwordErrors($source, $target)) !== []) {
            throw new RuntimeException('Password-hash validation failed: '.implode('; ', $errors));
        }

        return compact('counts', 'metrics', 'orphans');
    }

    /** @return array<string, int> */
    public function counts(ConnectionInterface $connection): array
    {
        $counts = [];
        foreach (AcademyMigrationManifest::TABLES as $table) {
            $counts[$table] = $connection->table($table)->count();
        }

        return $counts;
    }

    /** @return list<string> */
    public function schemaErrors(ConnectionInterface $source, ConnectionInterface $target): array
    {
        $errors = [];
        foreach (AcademyMigrationManifest::TABLES as $table) {
            $sourceColumns = $source->getSchemaBuilder()->getColumnListing($table);
            $targetColumns = $target->getSchemaBuilder()->getColumnListing($table);
            if ($sourceColumns !== $targetColumns) {
                $errors[] = "Column mismatch for {$table}: migrations differ between source and target.";
            }
        }

        return $errors;
    }

    /** @return array<string, int> */
    private function orphanCounts(ConnectionInterface $target): array
    {
        $rows = $target->select(<<<'SQL'
            SELECT con.oid AS constraint_id, con.conname AS constraint_name,
                   child.relname AS child_table, parent.relname AS parent_table,
                   child_col.attname AS child_column, parent_col.attname AS parent_column,
                   keys.ordinality AS position
            FROM pg_constraint con
            JOIN pg_class child ON child.oid = con.conrelid
            JOIN pg_namespace child_ns ON child_ns.oid = child.relnamespace
            JOIN pg_class parent ON parent.oid = con.confrelid
            CROSS JOIN LATERAL unnest(con.conkey, con.confkey)
                WITH ORDINALITY AS keys(child_attnum, parent_attnum, ordinality)
            JOIN pg_attribute child_col ON child_col.attrelid = child.oid AND child_col.attnum = keys.child_attnum
            JOIN pg_attribute parent_col ON parent_col.attrelid = parent.oid AND parent_col.attnum = keys.parent_attnum
            WHERE con.contype = 'f' AND child_ns.nspname = 'public'
            ORDER BY con.oid, keys.ordinality
            SQL);

        $constraints = [];
        foreach ($rows as $row) {
            if (! in_array($row->child_table, AcademyMigrationManifest::TABLES, true)) {
                continue;
            }
            $key = (string) $row->constraint_id;
            $constraints[$key]['name'] = $row->constraint_name;
            $constraints[$key]['child'] = $row->child_table;
            $constraints[$key]['parent'] = $row->parent_table;
            $constraints[$key]['columns'][] = [$row->child_column, $row->parent_column];
        }

        $counts = [];
        foreach ($constraints as $constraint) {
            $joins = [];
            $present = [];
            foreach ($constraint['columns'] as [$childColumn, $parentColumn]) {
                $joins[] = 'c.'.$this->quote($childColumn).' = p.'.$this->quote($parentColumn);
                $present[] = 'c.'.$this->quote($childColumn).' IS NOT NULL';
            }
            $firstParent = $constraint['columns'][0][1];
            $sql = 'SELECT COUNT(*) AS aggregate FROM '.$this->quote($constraint['child']).' c '
                .'LEFT JOIN '.$this->quote($constraint['parent']).' p ON '.implode(' AND ', $joins).' '
                .'WHERE '.implode(' AND ', $present).' AND p.'.$this->quote($firstParent).' IS NULL';
            $counts[$constraint['name']] = (int) $target->selectOne($sql)->aggregate;
        }

        return $counts;
    }

    /** @return array<string,int> */
    private function semanticMetrics(ConnectionInterface $connection): array
    {
        return [
            'users' => $connection->table('users')->count(),
            'tenant_memberships' => $connection->table('tenant_memberships')->count(),
            'student_enrollments' => $connection->table('student_enrollments')->count(),
            'school_years' => $connection->table('school_years')->count(),
            'cfisd_imported_calendar_events' => $connection->table('calendar_events')->whereNotNull('calendar_import_id')->count(),
            'curriculum_imports' => $connection->table('curriculum_imports')->count(),
            'curriculum_units' => $connection->table('curriculum_units')->count(),
            'lesson_plans' => $connection->table('lesson_plans')->count(),
            'generated_lesson_plans' => $connection->table('lesson_plans')->whereNotNull('generated_at')->count(),
            'generated_lessons' => $connection->table('lessons')->whereNotNull('generation_metadata')->count(),
            'approved_lessons' => $connection->table('lessons')->where('status', 'approved')->count(),
            'lesson_resources' => $connection->table('lesson_resources')->count(),
            'student_lesson_progress' => $connection->table('student_lesson_progress')->count(),
            'student_activity_responses' => $connection->table('student_activity_responses')->count(),
            'creative_writing_prompts' => $connection->table('creative_writing_prompts')->count(),
            'creative_writing_entries' => $connection->table('creative_writing_entries')->count(),
            'audit_logs' => $connection->table('audit_logs')->count(),
        ];
    }

    /** @return list<string> */
    private function sequenceErrors(ConnectionInterface $target): array
    {
        $errors = [];
        foreach (AcademyMigrationManifest::TABLES as $table) {
            if (! $this->hasColumn($target, $table, 'id')) {
                continue;
            }
            $sequence = $target->selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", [$table])->name ?? null;
            if (! $sequence) {
                continue;
            }
            [$schema, $name] = array_pad(explode('.', $sequence, 2), 2, null);
            if ($name === null) {
                [$schema, $name] = ['public', $schema];
            }
            $last = $target->table('pg_catalog.pg_sequences')->where('schemaname', $schema)
                ->where('sequencename', $name)->value('last_value');
            $max = $target->table($table)->max('id');
            if ($max !== null && ($last === null || (int) $last < (int) $max)) {
                $errors[] = "{$table} sequence is behind max(id)";
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function passwordErrors(ConnectionInterface $source, ConnectionInterface $target): array
    {
        $errors = [];
        $sourceRows = $source->table('users')->orderBy('id')->get(['id', 'password'])->keyBy('id');
        $targetRows = $target->table('users')->orderBy('id')->get(['id', 'password'])->keyBy('id');
        foreach ($sourceRows as $id => $sourceRow) {
            $sourceHash = (string) $sourceRow->password;
            $targetHash = (string) ($targetRows[$id]->password ?? '');
            if (! hash_equals($sourceHash, $targetHash)) {
                $errors[] = "user {$id} hash was not preserved exactly";
            } elseif ($sourceHash !== '' && password_get_info($sourceHash)['algoName'] === 'unknown') {
                $errors[] = "user {$id} has an unrecognized password-hash format";
            }
        }

        return $errors;
    }

    private function idDigest(ConnectionInterface $connection, string $table): string
    {
        $hash = hash_init('sha256');
        foreach ($connection->table($table)->orderBy('id')->pluck('id') as $id) {
            hash_update($hash, (string) $id."\n");
        }

        return hash_final($hash);
    }

    private function hasColumn(ConnectionInterface $connection, string $table, string $column): bool
    {
        return in_array($column, $connection->getSchemaBuilder()->getColumnListing($table), true);
    }

    private function quote(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Unsafe database identifier [{$identifier}].");
        }

        return '"'.$identifier.'"';
    }
}
