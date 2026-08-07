<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'student_enrollments_tenant_id_id_unique');
        });

        Schema::create('learning_plan_subject_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->boolean('is_hidden')->default(false);
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'student_enrollment_id', 'subject_id'], 'learning_plan_subject_preference_unique');
            $table->foreign(['tenant_id', 'student_enrollment_id'], 'learning_plan_subject_preference_enrollment_fk')
                ->references(['tenant_id', 'id'])->on('student_enrollments')->cascadeOnDelete();
            $table->index(['tenant_id', 'student_enrollment_id', 'is_hidden'], 'learning_plan_subject_preference_visibility_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_plan_subject_preferences');
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->dropUnique('student_enrollments_tenant_id_id_unique');
        });
    }
};
