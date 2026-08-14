<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->unsignedSmallInteger('estimated_preparation_minutes')->nullable()->after('estimated_minutes');
            $table->unsignedSmallInteger('suggested_sessions')->nullable()->after('estimated_preparation_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['estimated_preparation_minutes', 'suggested_sessions']);
        });
    }
};
