<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('education_provider_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('school_year_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('grade_level_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source_kind', 20);
            $table->string('source_category', 40);
            $table->string('authority_level', 30);
            $table->string('review_status', 20)->default('unreviewed');
            $table->string('processing_status', 20)->default('not_requested');
            $table->string('source_url', 2048)->nullable();
            $table->date('publication_date')->nullable();
            $table->timestamp('retrieved_at')->nullable();
            $table->string('version_label', 100)->nullable();
            $table->string('academic_year_label', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'source_category'], 'source_tenant_category_idx');
            $table->index(['tenant_id', 'source_kind'], 'source_tenant_kind_idx');
            $table->index(['tenant_id', 'review_status'], 'source_tenant_review_idx');
            $table->index(['tenant_id', 'processing_status'], 'source_tenant_processing_idx');
            $table->index(['tenant_id', 'school_year_id'], 'source_tenant_year_idx');
            $table->index(['tenant_id', 'education_provider_id'], 'source_tenant_provider_idx');
            $table->index(['tenant_id', 'grade_level_id'], 'source_tenant_grade_idx');
            $table->index(['tenant_id', 'archived_at'], 'source_tenant_archived_idx');
        });

        Schema::create('academic_source_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('current_key', 20)->nullable();
            $table->boolean('is_current')->default(true);
            $table->string('disk', 40);
            $table->string('stored_path', 500);
            $table->string('stored_filename');
            $table->string('original_filename');
            $table->string('mime_type', 120);
            $table->string('extension', 12);
            $table->unsignedBigInteger('file_size');
            $table->char('checksum_sha256', 64);
            $table->timestamp('uploaded_at');
            $table->timestamps();
            $table->unique(['academic_source_id', 'version_number'], 'source_file_version_unique');
            $table->unique(['academic_source_id', 'current_key'], 'source_file_current_unique');
            $table->index(['academic_source_id', 'is_current'], 'source_file_current_idx');
            $table->index(['academic_source_id', 'checksum_sha256'], 'source_file_checksum_idx');
            $table->index('uploaded_by_user_id', 'source_file_uploader_idx');
        });

        Schema::create('academic_source_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_source_id')->constrained()->restrictOnDelete();
            $table->string('link_type', 40);
            $table->unsignedBigInteger('link_id');
            $table->timestamps();
            $table->unique(['academic_source_id', 'link_type', 'link_id'], 'academic_source_link_unique');
            $table->index(['link_type', 'link_id'], 'academic_source_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_source_links');
        Schema::dropIfExists('academic_source_files');
        Schema::dropIfExists('academic_sources');
    }
};
