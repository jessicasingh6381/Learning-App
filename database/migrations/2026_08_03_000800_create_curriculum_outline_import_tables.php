<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_source_file_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_package_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_package_course_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('grade_level_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('school_year_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('standards_framework_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('processing');
            $table->string('parser_key', 80);
            $table->string('parser_version', 50);
            $table->string('extraction_method', 50)->default('pdf_positioned_text');
            $table->string('source_title')->nullable();
            $table->date('source_revision_date')->nullable();
            $table->text('diagnostic')->nullable();
            $table->unsignedInteger('review_version')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['academic_source_file_id', 'curriculum_package_course_id'], 'curriculum_import_file_target_unique');
            $table->index(['tenant_id', 'status']);
            $table->index(['academic_source_id', 'created_at']);
            $table->index(['curriculum_package_course_id', 'status'], 'curriculum_import_target_status_idx');
        });

        Schema::create('curriculum_import_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_proposal_id')->nullable()->constrained('curriculum_import_proposals')->nullOnDelete();
            $table->string('proposal_type', 30);
            $table->boolean('included')->default(true);
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('name');
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->unsignedSmallInteger('estimated_days')->nullable();
            $table->string('unit_type', 40)->nullable();
            $table->string('reporting_period')->nullable();
            $table->json('standard_codes')->nullable();
            $table->unsignedInteger('source_page')->nullable();
            $table->text('raw_text')->nullable();
            $table->text('parser_note')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('manually_edited')->default(false);
            $table->json('original_values')->nullable();
            $table->json('parser_metadata')->nullable();
            $table->timestamps();
            $table->index(['curriculum_import_id', 'proposal_type', 'sequence'], 'curriculum_proposal_type_sort_idx');
            $table->index(['parent_proposal_id', 'sequence'], 'curriculum_proposal_parent_sort_idx');
        });

        Schema::create('curriculum_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_package_course_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sequence');
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->string('period_type', 40)->nullable();
            $table->string('status', 20)->default('draft');
            $this->provenance($table);
            $table->timestamps();
            $table->unique(['curriculum_package_course_id', 'sequence'], 'curriculum_period_target_sequence_unique');
            $table->unique('curriculum_import_proposal_id', 'curriculum_period_proposal_unique');
        });

        Schema::create('curriculum_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_package_course_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sequence');
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->unsignedSmallInteger('estimated_days')->nullable();
            $table->string('unit_type', 40)->default('instructional');
            $table->boolean('included')->default(true);
            $this->provenance($table);
            $table->timestamps();
            $table->unique(['curriculum_package_course_id', 'sequence'], 'curriculum_unit_target_sequence_unique');
            $table->unique('curriculum_import_proposal_id', 'curriculum_unit_proposal_unique');
            $table->index(['curriculum_period_id', 'sequence']);
        });

        Schema::create('curriculum_unit_standard_alignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_unit_id')->constrained(indexName: 'curriculum_alignment_unit_fk')->restrictOnDelete();
            $table->foreignId('standards_framework_id')->constrained(indexName: 'curriculum_alignment_framework_fk')->restrictOnDelete();
            $table->string('standard_code', 100);
            $table->string('normalized_code', 100)->nullable();
            $this->provenance($table);
            $table->timestamps();
            $table->unique(['curriculum_unit_id', 'standards_framework_id', 'normalized_code'], 'curriculum_unit_standard_unique');
            $table->index(['standards_framework_id', 'normalized_code'], 'curriculum_standard_lookup_idx');
        });
    }

    private function provenance(Blueprint $table): void
    {
        $prefix = match ($table->getTable()) {
            'curriculum_periods' => 'curriculum_period',
            'curriculum_units' => 'curriculum_unit',
            default => 'curriculum_alignment',
        };
        $table->foreignId('academic_source_id')->constrained(indexName: $prefix.'_source_fk')->restrictOnDelete();
        $table->foreignId('academic_source_file_id')->constrained(indexName: $prefix.'_file_fk')->restrictOnDelete();
        $table->foreignId('curriculum_import_id')->constrained(indexName: $prefix.'_import_fk')->restrictOnDelete();
        $table->foreignId('curriculum_import_proposal_id')->constrained(indexName: $prefix.'_proposal_fk')->restrictOnDelete();
        $table->unsignedInteger('source_page')->nullable();
        $table->text('source_raw_text')->nullable();
        $table->string('parser_key', 80);
        $table->string('parser_version', 50);
        $table->decimal('source_confidence', 4, 3)->nullable();
        $table->text('source_note')->nullable();
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_unit_standard_alignments');
        Schema::dropIfExists('curriculum_units');
        Schema::dropIfExists('curriculum_periods');
        Schema::dropIfExists('curriculum_import_proposals');
        Schema::dropIfExists('curriculum_imports');
    }
};
