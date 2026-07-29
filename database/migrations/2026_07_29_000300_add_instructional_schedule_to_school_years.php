<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_years', function (Blueprint $table) {
            $table->string('instructional_week_type', 20)
                ->default('five_day')
                ->after('instructional_day_target');
            $table->json('instructional_weekdays')
                ->nullable()
                ->after('instructional_week_type');
        });

        DB::table('school_years')
            ->whereNull('instructional_weekdays')
            ->update([
                'instructional_week_type' => 'five_day',
                'instructional_weekdays' => json_encode([1, 2, 3, 4, 5], JSON_THROW_ON_ERROR),
            ]);
    }

    public function down(): void
    {
        Schema::table('school_years', function (Blueprint $table) {
            $table->dropColumn([
                'instructional_week_type',
                'instructional_weekdays',
            ]);
        });
    }
};
