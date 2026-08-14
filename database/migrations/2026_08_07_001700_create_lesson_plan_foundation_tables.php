<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_imports', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'curriculum_imports_tenant_id_id_unique');
        });
        Schema::table('curriculum_unit_components', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'curriculum_components_tenant_id_id_unique');
        });

        Schema::create('lesson_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_import_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_package_course_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('revision')->default(1);
            $table->string('generator_key', 100)->nullable();
            $table->string('generator_version', 50)->nullable();
            $table->json('generation_metadata')->nullable();
            $table->text('failure_diagnostic')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('superseded_by_lesson_plan_id')->nullable()
                ->constrained('lesson_plans', indexName: 'lesson_plans_superseded_by_fk')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'lesson_plans_tenant_id_id_unique');
            $table->unique(
                ['tenant_id', 'student_enrollment_id', 'curriculum_import_id', 'revision'],
                'lesson_plans_context_revision_unique'
            );
            $table->foreign(['tenant_id', 'student_enrollment_id'], 'lesson_plans_tenant_enrollment_fk')
                ->references(['tenant_id', 'id'])->on('student_enrollments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'curriculum_import_id'], 'lesson_plans_tenant_import_fk')
                ->references(['tenant_id', 'id'])->on('curriculum_imports')->restrictOnDelete();
            $table->index(['tenant_id', 'student_enrollment_id', 'status'], 'lesson_plans_enrollment_status_idx');
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_unit_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('title');
            $table->string('lesson_mode', 20)->default('full');
            $table->string('status', 20)->default('draft');
            $table->text('learning_objective')->nullable();
            $table->text('completion_criteria')->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->string('generator_key', 100)->nullable();
            $table->string('generator_version', 50)->nullable();
            $table->json('generation_metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'lessons_tenant_id_id_unique');
            $table->unique(['lesson_plan_id', 'sequence'], 'lessons_plan_sequence_unique');
            $table->foreign(['tenant_id', 'lesson_plan_id'], 'lessons_tenant_plan_fk')
                ->references(['tenant_id', 'id'])->on('lesson_plans')->cascadeOnDelete();
            $table->index(['tenant_id', 'status'], 'lessons_tenant_status_idx');
        });

        Schema::create('lesson_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_section_id')->nullable()
                ->constrained('lesson_sections', indexName: 'lesson_sections_parent_fk')->cascadeOnDelete();
            $table->string('section_type', 50);
            $table->unsignedSmallInteger('sequence');
            $table->string('title')->nullable();
            $table->longText('content')->nullable();
            $table->string('audience', 20)->default('shared');
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'lesson_sections_tenant_id_id_unique');
            $table->foreign(['tenant_id', 'lesson_id'], 'lesson_sections_tenant_lesson_fk')
                ->references(['tenant_id', 'id'])->on('lessons')->cascadeOnDelete();
            $table->index(['lesson_id', 'parent_section_id', 'sequence'], 'lesson_sections_tree_idx');
        });

        Schema::create('lesson_curriculum_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_unit_component_id')
                ->constrained(indexName: 'lesson_component_curriculum_fk')->restrictOnDelete();
            $table->string('role', 40)->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();
            $table->unique(['lesson_id', 'curriculum_unit_component_id'], 'lesson_component_unique');
            $table->foreign(['tenant_id', 'lesson_id'], 'lesson_components_tenant_lesson_fk')
                ->references(['tenant_id', 'id'])->on('lessons')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'curriculum_unit_component_id'], 'lesson_components_tenant_component_fk')
                ->references(['tenant_id', 'id'])->on('curriculum_unit_components')->restrictOnDelete();
        });

        Schema::create('lesson_standard_alignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_unit_standard_alignment_id')
                ->constrained('curriculum_unit_standard_alignments', indexName: 'lesson_standard_alignment_fk')
                ->restrictOnDelete();
            $table->timestamps();
            $table->unique(
                ['lesson_id', 'curriculum_unit_standard_alignment_id'],
                'lesson_standard_alignment_unique'
            );
            $table->foreign(['tenant_id', 'lesson_id'], 'lesson_standards_tenant_lesson_fk')
                ->references(['tenant_id', 'id'])->on('lessons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_standard_alignments');
        Schema::dropIfExists('lesson_curriculum_components');
        Schema::dropIfExists('lesson_sections');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('lesson_plans');
        Schema::table('curriculum_unit_components', function (Blueprint $table) {
            $table->dropUnique('curriculum_components_tenant_id_id_unique');
        });
        Schema::table('curriculum_imports', function (Blueprint $table) {
            $table->dropUnique('curriculum_imports_tenant_id_id_unique');
        });
    }
};
