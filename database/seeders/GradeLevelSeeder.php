<?php

namespace Database\Seeders;

use App\Models\GradeLevel;
use Illuminate\Database\Seeder;

class GradeLevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [['PK', 'Pre-K'], ['K', 'Kindergarten']];
        foreach (range(1, 12) as $grade) {
            $levels[] = ['G'.$grade, 'Grade '.$grade];
        }
        $levels[] = ['UG', 'Ungraded'];

        foreach ($levels as $order => [$code, $name]) {
            GradeLevel::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'sort_order' => $order, 'is_active' => true],
            );
        }
    }
}
