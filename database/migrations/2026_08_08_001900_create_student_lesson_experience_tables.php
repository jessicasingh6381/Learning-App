<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('preview');
            $table->string('theme_key', 60)->nullable();
            $table->string('mission_title');
            $table->text('mission_brief')->nullable();
            $table->string('completion_title')->nullable();
            $table->text('completion_message')->nullable();
            $table->string('source_version', 40)->default('prototype-v1');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'lesson_experiences_tenant_id_id_unique');
            $table->unique('lesson_id');
            $table->foreign(['tenant_id', 'lesson_id'], 'lesson_experiences_tenant_lesson_fk')
                ->references(['tenant_id', 'id'])->on('lessons')->cascadeOnDelete();
        });

        Schema::create('lesson_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_experience_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_lesson_section_id')->nullable()->constrained('lesson_sections')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('activity_type', 50);
            $table->string('display_title');
            $table->text('student_instructions')->nullable();
            $table->longText('content')->nullable();
            $table->json('interaction_data')->nullable();
            $table->json('answer_data')->nullable();
            $table->json('hints')->nullable();
            $table->json('feedback')->nullable();
            $table->json('completion_condition')->nullable();
            $table->string('reward_label', 100)->nullable();
            $table->string('theme_key', 60)->nullable();
            $table->boolean('requires_teacher_review')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'lesson_activities_tenant_id_id_unique');
            $table->unique(['lesson_experience_id', 'sequence'], 'lesson_activities_experience_sequence_unique');
            $table->foreign(['tenant_id', 'lesson_experience_id'], 'lesson_activities_tenant_experience_fk')
                ->references(['tenant_id', 'id'])->on('lesson_experiences')->cascadeOnDelete();
        });

        Schema::create('student_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_experience_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('previewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('current_activity_id')->nullable()->constrained('lesson_activities')->nullOnDelete();
            $table->boolean('is_preview')->default(false);
            $table->string('status', 20)->default('not_started');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'student_lesson_progress_tenant_id_id_unique');
            $table->unique(['lesson_experience_id', 'student_enrollment_id', 'is_preview'], 'student_lesson_progress_context_unique');
            $table->foreign(['tenant_id', 'lesson_experience_id'], 'student_progress_tenant_experience_fk')
                ->references(['tenant_id', 'id'])->on('lesson_experiences')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'student_enrollment_id'], 'student_progress_tenant_enrollment_fk')
                ->references(['tenant_id', 'id'])->on('student_enrollments')->restrictOnDelete();
        });

        Schema::create('student_activity_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_lesson_progress_id')->constrained('student_lesson_progress')->cascadeOnDelete();
            $table->foreignId('lesson_activity_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('in_progress');
            $table->json('response')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->text('feedback')->nullable();
            $table->string('teacher_review_status', 20)->default('not_required');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['student_lesson_progress_id', 'lesson_activity_id'], 'student_activity_response_unique');
            $table->foreign(['tenant_id', 'student_lesson_progress_id'], 'student_responses_tenant_progress_fk')
                ->references(['tenant_id', 'id'])->on('student_lesson_progress')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'lesson_activity_id'], 'student_responses_tenant_activity_fk')
                ->references(['tenant_id', 'id'])->on('lesson_activities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_activity_responses');
        Schema::dropIfExists('student_lesson_progress');
        Schema::dropIfExists('lesson_activities');
        Schema::dropIfExists('lesson_experiences');
    }
};
