<?php

namespace Tests\Unit;

use App\Services\AcademySchemaComparator;
use PHPUnit\Framework\TestCase;

class AcademySchemaComparatorTest extends TestCase
{
    public function test_it_ignores_order_but_accepts_only_known_cross_engine_equivalents(): void
    {
        $source = [
            $this->mysql('id', 'bigint', 'bigint(20) unsigned', nullable: false, extra: 'auto_increment'),
            $this->mysql('enabled', 'tinyint', 'tinyint(1)', nullable: false, default: '0'),
            $this->mysql('metadata', 'longtext', 'longtext'),
            $this->mysql('uuid', 'char', 'char(36)', length: 36, nullable: false),
            $this->mysql('name', 'varchar', 'varchar(40)', length: 40, nullable: false, default: "'active'"),
        ];
        $target = [
            $this->postgres('name', 'character varying', length: 40, nullable: false, default: "'active'::character varying"),
            $this->postgres('metadata', 'json'),
            $this->postgres('uuid', 'uuid', nullable: false),
            $this->postgres('id', 'bigint', nullable: false, default: "nextval('example_id_seq'::regclass)"),
            $this->postgres('enabled', 'boolean', nullable: false, default: 'false'),
        ];

        $this->assertSame([], (new AcademySchemaComparator)->compareDefinitions($source, $target, 'example'));
    }

    public function test_only_known_mysql_implicit_timestamp_defaults_are_allowed(): void
    {
        $source = [$this->mysql('uploaded_at', 'timestamp', 'timestamp', nullable: false, default: 'current_timestamp()')];
        $target = [$this->postgres('uploaded_at', 'timestamp without time zone', nullable: false)];

        $this->assertSame([], (new AcademySchemaComparator)->compareDefinitions($source, $target, 'academic_source_files'));
        $this->assertStringContainsString(
            'Default mismatch',
            (new AcademySchemaComparator)->compareDefinitions($source, $target, 'unapproved_table')[0]
        );
    }

    public function test_it_rejects_missing_unexpected_and_incompatible_columns(): void
    {
        $source = [
            $this->mysql('id', 'bigint', 'bigint(20) unsigned', nullable: false, extra: 'auto_increment'),
            $this->mysql('required_name', 'varchar', 'varchar(40)', length: 40, nullable: false, default: "'draft'"),
            $this->mysql('missing_column', 'text', 'text'),
        ];
        $target = [
            $this->postgres('id', 'bigint', nullable: false),
            $this->postgres('required_name', 'character varying', length: 80, default: "'active'::character varying"),
            $this->postgres('unexpected_column', 'text'),
        ];

        $errors = (new AcademySchemaComparator)->compareDefinitions($source, $target, 'example');

        $this->assertTrue(collect($errors)->contains(fn ($error) => str_contains($error, 'missing application column missing_column')));
        $this->assertTrue(collect($errors)->contains(fn ($error) => str_contains($error, 'unexpected application column unexpected_column')));
        $this->assertTrue(collect($errors)->contains(fn ($error) => str_contains($error, 'Incompatible type for example.required_name')));
        $this->assertTrue(collect($errors)->contains(fn ($error) => str_contains($error, 'Auto-increment/sequence mismatch for example.id')));
    }

    /** @return array<string,mixed> */
    private function mysql(
        string $name,
        string $dataType,
        string $columnType,
        bool $nullable = true,
        mixed $default = null,
        string $extra = '',
        ?int $length = null
    ): array {
        return [
            'column_name' => $name, 'data_type' => $dataType, 'column_type' => $columnType,
            'is_nullable' => $nullable ? 'YES' : 'NO', 'column_default' => $default, 'extra' => $extra,
            'generation_expression' => null, 'character_maximum_length' => $length,
            'numeric_precision' => null, 'numeric_scale' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function postgres(
        string $name,
        string $dataType,
        bool $nullable = true,
        mixed $default = null,
        ?int $length = null
    ): array {
        return [
            'column_name' => $name, 'data_type' => $dataType,
            'is_nullable' => $nullable ? 'YES' : 'NO', 'column_default' => $default,
            'is_identity' => 'NO', 'is_generated' => 'NEVER', 'generation_expression' => null,
            'character_maximum_length' => $length, 'numeric_precision' => null, 'numeric_scale' => null,
        ];
    }
}
