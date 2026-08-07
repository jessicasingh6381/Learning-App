<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_imports', function (Blueprint $table) {
            $table->string('import_type', 30)->default('curriculum_outline')->after('standards_framework_id');
            $table->foreignId('curriculum_package_id')->nullable()->change();
            $table->foreignId('curriculum_package_course_id')->nullable()->change();
            $table->string('document_section', 100)->nullable()->after('source_revision_date');
            $table->string('adopted_label', 100)->nullable()->after('document_section');
            $table->text('introduction_text')->nullable()->after('adopted_label');
            $table->json('document_metadata')->nullable()->after('introduction_text');
            $table->unique(
                ['academic_source_file_id', 'import_type', 'subject_id', 'grade_level_id', 'standards_framework_id'],
                'curriculum_import_file_mode_context_unique'
            );
        });

        Schema::table('curriculum_import_proposals', function (Blueprint $table) {
            $table->string('strand', 100)->nullable()->after('component_type');
            $table->string('standard_code', 100)->nullable()->after('strand');
            $table->string('normalized_code', 100)->nullable()->after('standard_code');
            $table->text('statement')->nullable()->after('normalized_code');
            $table->index(['curriculum_import_id', 'normalized_code'], 'curriculum_proposal_standard_lookup_idx');
        });

        Schema::create('standards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('ownership_key', 40);
            $table->foreignId('standards_framework_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('grade_level_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('parent_standard_id')->nullable()->constrained('standards')->restrictOnDelete();
            $table->string('record_type', 30);
            $table->string('title');
            $table->string('standard_code', 100)->nullable();
            $table->string('normalized_code', 100);
            $table->string('strand', 100)->nullable();
            $table->text('statement')->nullable();
            $table->unsignedSmallInteger('sequence');
            $table->string('version_label', 100);
            $table->string('adopted_label', 100)->nullable();
            $table->string('effective_label', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('academic_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_source_file_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_import_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_import_proposal_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('source_page')->nullable();
            $table->text('source_raw_text')->nullable();
            $table->string('parser_key', 80);
            $table->string('parser_version', 50);
            $table->decimal('source_confidence', 4, 3)->nullable();
            $table->text('source_note')->nullable();
            $table->timestamps();
            $table->unique(
                ['ownership_key', 'standards_framework_id', 'subject_id', 'grade_level_id', 'version_label', 'normalized_code'],
                'standards_context_code_unique'
            );
            $table->unique('curriculum_import_proposal_id', 'standards_proposal_unique');
            $table->index(['standards_framework_id', 'subject_id', 'grade_level_id', 'normalized_code'], 'standards_lookup_idx');
            $table->index(['parent_standard_id', 'sequence'], 'standards_parent_sort_idx');
        });

        Schema::table('curriculum_unit_standard_alignments', function (Blueprint $table) {
            $table->foreignId('standard_id')->nullable()->after('standards_framework_id')
                ->constrained('standards', indexName: 'curriculum_alignment_standard_fk')->restrictOnDelete();
            $table->index('standard_id', 'curriculum_alignment_standard_idx');
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_unit_standard_alignments', function (Blueprint $table) {
            $table->dropForeign('curriculum_alignment_standard_fk');
            $table->dropIndex('curriculum_alignment_standard_idx');
            $table->dropColumn('standard_id');
        });
        Schema::dropIfExists('standards');
        Schema::table('curriculum_import_proposals', function (Blueprint $table) {
            $table->dropIndex('curriculum_proposal_standard_lookup_idx');
            $table->dropColumn(['strand', 'standard_code', 'normalized_code', 'statement']);
        });
        Schema::table('curriculum_imports', function (Blueprint $table) {
            $table->dropUnique('curriculum_import_file_mode_context_unique');
            $table->dropColumn(['import_type', 'document_section', 'adopted_label', 'introduction_text', 'document_metadata']);
            $table->foreignId('curriculum_package_id')->nullable(false)->change();
            $table->foreignId('curriculum_package_course_id')->nullable(false)->change();
        });
    }
};
