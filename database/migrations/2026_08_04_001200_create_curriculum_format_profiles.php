<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('curriculum_format_profiles')) {
            Schema::table('curriculum_format_profiles', function (Blueprint $table) {
                $table->foreign('example_academic_source_file_id', 'cfp_example_file_fk')->references('id')->on('academic_source_files')->nullOnDelete();
                $table->foreign('created_by_user_id', 'cfp_created_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('reviewed_by_user_id', 'cfp_reviewed_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->unique('example_academic_source_file_id', 'curriculum_format_example_file_unique');
                $table->index(['tenant_id', 'status'], 'cfp_tenant_status_idx');
                $table->index(['education_provider_id', 'subject_id', 'status'], 'curriculum_format_context_idx');
            });
            return;
        }
        Schema::create('curriculum_format_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable();
            $table->string('ownership_scope', 20)->default('tenant');
            $table->foreignId('education_provider_id')->nullable();
            $table->foreignId('subject_id')->nullable();
            $table->foreignId('minimum_grade_level_id')->nullable();
            $table->foreignId('maximum_grade_level_id')->nullable();
            $table->foreignId('example_academic_source_id')->nullable();
            $table->foreignId('example_academic_source_file_id')->nullable();
            $table->string('name');
            $table->string('document_family', 100);
            $table->string('file_type', 50)->default('application/pdf');
            $table->json('recognition_fingerprints');
            $table->json('mapping_rules');
            $table->json('detected_structure');
            $table->unsignedSmallInteger('profile_version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by_user_id')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique('example_academic_source_file_id', 'curriculum_format_example_file_unique');
            $table->foreign('tenant_id', 'cfp_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('education_provider_id', 'cfp_provider_fk')->references('id')->on('education_providers')->restrictOnDelete();
            $table->foreign('subject_id', 'cfp_subject_fk')->references('id')->on('subjects')->restrictOnDelete();
            $table->foreign('minimum_grade_level_id', 'cfp_min_grade_fk')->references('id')->on('grade_levels')->restrictOnDelete();
            $table->foreign('maximum_grade_level_id', 'cfp_max_grade_fk')->references('id')->on('grade_levels')->restrictOnDelete();
            $table->foreign('example_academic_source_id', 'cfp_example_source_fk')->references('id')->on('academic_sources')->nullOnDelete();
            $table->foreign('example_academic_source_file_id', 'cfp_example_file_fk')->references('id')->on('academic_source_files')->nullOnDelete();
            $table->foreign('created_by_user_id', 'cfp_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by_user_id', 'cfp_reviewed_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'status'], 'cfp_tenant_status_idx');
            $table->index(['education_provider_id', 'subject_id', 'status'], 'curriculum_format_context_idx');
        });
    }

    public function down(): void { Schema::dropIfExists('curriculum_format_profiles'); }
};
