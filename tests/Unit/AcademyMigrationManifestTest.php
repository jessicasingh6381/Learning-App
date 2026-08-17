<?php

namespace Tests\Unit;

use App\Services\AcademyMigrationManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AcademyMigrationManifestTest extends TestCase
{
    public function test_manifest_covers_every_non_runtime_table_created_by_migrations(): void
    {
        $created = [];
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $migration) {
            preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($migration), $matches);
            $created = [...$created, ...$matches[1]];
        }

        $applicationTables = array_values(array_diff(array_unique($created), AcademyMigrationManifest::EXCLUDED_RUNTIME_TABLES));
        $this->assertEqualsCanonicalizing($applicationTables, AcademyMigrationManifest::TABLES);
        $this->assertSame(AcademyMigrationManifest::TABLES, array_values(array_unique(AcademyMigrationManifest::TABLES)));
    }

    #[DataProvider('dependencyPairs')]
    public function test_foreign_key_dependencies_precede_children(string $parent, string $child): void
    {
        $this->assertLessThan(
            array_search($child, AcademyMigrationManifest::TABLES, true),
            array_search($parent, AcademyMigrationManifest::TABLES, true),
            "{$parent} must precede {$child}"
        );
    }

    /** @return array<string,array{string,string}> */
    public static function dependencyPairs(): array
    {
        return [
            'tenant membership' => ['tenants', 'tenant_memberships'],
            'student enrollment' => ['students', 'student_enrollments'],
            'academic source file' => ['academic_sources', 'academic_source_files'],
            'calendar import' => ['academic_source_files', 'calendar_imports'],
            'curriculum import' => ['academic_source_files', 'curriculum_imports'],
            'curriculum unit' => ['curriculum_periods', 'curriculum_units'],
            'standards alignment' => ['standards', 'curriculum_unit_standard_alignments'],
            'lesson' => ['lesson_plans', 'lessons'],
            'activity' => ['lesson_experiences', 'lesson_activities'],
            'student progress' => ['lesson_activities', 'student_lesson_progress'],
            'journal entry' => ['creative_writing_prompts', 'creative_writing_entries'],
        ];
    }
}
