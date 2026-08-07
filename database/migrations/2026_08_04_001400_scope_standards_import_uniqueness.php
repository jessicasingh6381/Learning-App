<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_imports', function (Blueprint $table) {
            $table->dropUnique('curriculum_import_file_mode_context_unique');
            $table->string('import_context_key', 191)->nullable()->after('import_type');
            $table->unique('import_context_key', 'curriculum_import_context_unique');
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_imports', function (Blueprint $table) {
            $table->dropUnique('curriculum_import_context_unique');
            $table->dropColumn('import_context_key');
            $table->unique(['academic_source_file_id', 'import_type', 'subject_id', 'grade_level_id', 'standards_framework_id'], 'curriculum_import_file_mode_context_unique');
        });
    }
};
