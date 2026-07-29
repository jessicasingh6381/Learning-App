<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AcademicReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::table('education_providers')->updateOrInsert(
            ['ownership_key' => 'platform', 'name' => 'Cypress-Fairbanks Independent School District'],
            [
                'tenant_id' => null,
                'short_name' => 'CFISD',
                'provider_type' => 'district',
                'state_or_region' => 'Texas',
                'country_code' => 'US',
                'status' => 'active',
                'notes' => 'Reference provider container only; no official calendar, pacing, or curriculum content is imported.',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        DB::table('standards_frameworks')->updateOrInsert(
            [
                'ownership_key' => 'platform',
                'name' => 'Texas Essential Knowledge and Skills',
                'version_label' => 'unversioned',
            ],
            [
                'tenant_id' => null,
                'short_name' => 'TEKS',
                'jurisdiction' => 'Texas',
                'status' => 'active',
                'notes' => 'Framework container only; individual TEKS standards have not been imported.',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $subjects = [
            ['ELAR', 'English Language Arts and Reading'],
            ['MATH', 'Mathematics'],
            ['SCI', 'Science'],
            ['SS', 'Social Studies'],
            ['ART', 'Art'],
            ['MUSIC', 'Music'],
            ['PE', 'Physical Education'],
            ['HEALTH', 'Health'],
            ['TECH', 'Technology'],
            ['LANG', 'World Languages'],
            ['ELEC', 'Electives'],
            ['OTHER', 'Other'],
        ];

        foreach ($subjects as $sortOrder => [$code, $name]) {
            DB::table('subjects')->updateOrInsert(
                ['ownership_key' => 'platform', 'code' => $code],
                [
                    'tenant_id' => null,
                    'name' => $name,
                    'sort_order' => $sortOrder,
                    'status' => 'active',
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }
}
