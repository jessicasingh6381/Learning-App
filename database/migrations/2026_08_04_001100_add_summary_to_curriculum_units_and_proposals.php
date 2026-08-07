<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_import_proposals', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('description');
            $table->unsignedSmallInteger('extraction_generation')->default(1)->after('curriculum_import_id');
            $table->timestamp('superseded_at')->nullable()->after('manually_edited');
            $table->index(['curriculum_import_id', 'extraction_generation', 'superseded_at'], 'curriculum_proposal_generation_idx');
        });
        Schema::table('curriculum_units', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_units', fn (Blueprint $table) => $table->dropColumn('summary'));
        Schema::table('curriculum_import_proposals', function (Blueprint $table) {
            $table->dropIndex('curriculum_proposal_generation_idx');
            $table->dropColumn(['summary', 'extraction_generation', 'superseded_at']);
        });
    }
};
