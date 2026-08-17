<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_writing_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('prompt');
            $table->json('include_hints');
            $table->string('category', 80)->nullable();
            $table->foreignId('minimum_grade_level_id')->nullable()->constrained('grade_levels')->nullOnDelete();
            $table->foreignId('maximum_grade_level_id')->nullable()->constrained('grade_levels')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->string('source_type', 30)->default('teacher_created');
            $table->string('source_key', 100)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'creative_prompts_tenant_id_id_unique');
            $table->unique(['tenant_id', 'source_key'], 'creative_prompts_tenant_source_unique');
            $table->index(['tenant_id', 'active', 'category'], 'creative_prompts_eligibility_index');
        });

        Schema::create('creative_writing_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('creative_writing_prompt_id')->constrained()->restrictOnDelete();
            $table->date('instructional_date');
            $table->string('prompt_title_snapshot');
            $table->text('prompt_snapshot');
            $table->json('include_hints_snapshot');
            $table->string('category_snapshot', 80)->nullable();
            $table->longText('response')->nullable();
            $table->string('status', 20)->default('assigned');
            $table->unsignedInteger('word_count')->default(0);
            $table->timestamp('assigned_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_saved_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('teacher_feedback')->nullable();
            $table->foreignId('feedback_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('feedback_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'creative_entries_tenant_id_id_unique');
            $table->unique(['tenant_id', 'student_id', 'instructional_date'], 'creative_entries_student_date_unique');
            $table->index(['tenant_id', 'student_enrollment_id', 'instructional_date'], 'creative_entries_enrollment_date_index');
            $table->index(['tenant_id', 'status', 'instructional_date'], 'creative_entries_status_date_index');
            $table->foreign(['tenant_id', 'student_id'], 'creative_entries_tenant_student_fk')->references(['tenant_id', 'id'])->on('students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_enrollment_id'], 'creative_entries_tenant_enrollment_fk')->references(['tenant_id', 'id'])->on('student_enrollments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'school_year_id'], 'creative_entries_tenant_year_fk')->references(['tenant_id', 'id'])->on('school_years')->restrictOnDelete();
            $table->foreign(['tenant_id', 'creative_writing_prompt_id'], 'creative_entries_tenant_prompt_fk')->references(['tenant_id', 'id'])->on('creative_writing_prompts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_writing_entries');
        Schema::dropIfExists('creative_writing_prompts');
    }
};
