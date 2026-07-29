<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('education_providers')) {
            Schema::create('education_providers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('ownership_key', 40);
                $table->string('name');
                $table->string('short_name', 60)->nullable();
                $table->string('provider_type', 40);
                $table->string('state_or_region', 100)->nullable();
                $table->char('country_code', 2)->default('US');
                $table->string('website_url', 2048)->nullable();
                $table->string('status', 20)->default('active');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['ownership_key', 'name']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('standards_frameworks')) {
            Schema::create('standards_frameworks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('education_provider_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('ownership_key', 40);
                $table->string('name');
                $table->string('short_name', 60)->nullable();
                $table->string('jurisdiction', 100)->nullable();
                $table->string('version_label', 100)->default('unversioned');
                $table->date('effective_start_date')->nullable();
                $table->date('effective_end_date')->nullable();
                $table->string('status', 20)->default('draft');
                $table->string('source_url', 2048)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['ownership_key', 'name', 'version_label'], 'framework_owner_name_version_unique');
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('subjects')) {
            Schema::create('subjects', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('ownership_key', 40);
                $table->string('name');
                $table->string('code', 40);
                $table->text('description')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('status', 20)->default('active');
                $table->timestamps();
                $table->unique(['ownership_key', 'code']);
                $table->index(['tenant_id', 'status', 'sort_order']);
            });
        }

        if (! Schema::hasTable('calendar_profiles')) {
            Schema::create('calendar_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('education_provider_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('ownership_key', 40);
                $table->string('name');
                $table->string('academic_year_label', 100)->default('');
                $table->date('start_date');
                $table->date('end_date');
                $table->string('timezone', 64);
                $table->string('status', 20)->default('draft');
                $table->string('source_type', 30);
                $table->string('source_url', 2048)->nullable();
                $table->string('source_version', 100)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['ownership_key', 'name', 'academic_year_label'], 'calendar_owner_name_year_unique');
                $table->index(['tenant_id', 'status']);
                $table->index(['start_date', 'end_date']);
            });
        }

        if (! Schema::hasTable('calendar_events')) {
            Schema::create('calendar_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('calendar_profile_id')->constrained()->restrictOnDelete();
                $table->date('event_date');
                $table->date('end_date')->nullable();
                $table->string('event_type', 40);
                $table->string('name');
                $table->string('instructional_effect', 30);
                $table->string('status', 20)->default('active');
                $table->text('notes')->nullable();
                $table->string('source_reference', 2048)->nullable();
                $table->timestamps();
                $table->index(['calendar_profile_id', 'status', 'event_date']);
            });
        }

        if (! Schema::hasTable('courses')) {
            Schema::create('courses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('subject_id')->constrained()->restrictOnDelete();
                $table->foreignId('standards_framework_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('education_provider_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('minimum_grade_level_id')->nullable()->constrained('grade_levels')->restrictOnDelete();
                $table->foreignId('maximum_grade_level_id')->nullable()->constrained('grade_levels')->restrictOnDelete();
                $table->string('ownership_key', 40);
                $table->string('name');
                $table->string('code', 80);
                $table->text('description')->nullable();
                $table->string('status', 20)->default('draft');
                $table->timestamps();
                $table->unique(['ownership_key', 'code']);
                $table->index(['tenant_id', 'subject_id', 'status']);
            });
        }

        if (! Schema::hasTable('curriculum_packages')) {
            Schema::create('curriculum_packages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('education_provider_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('standards_framework_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('ownership_key', 40);
                $table->string('name');
                $table->string('version_label', 100);
                $table->text('description')->nullable();
                $table->string('status', 20)->default('draft');
                $table->date('effective_start_date')->nullable();
                $table->date('effective_end_date')->nullable();
                $table->string('source_url', 2048)->nullable();
                $table->timestamps();
                $table->unique(['ownership_key', 'name', 'version_label'], 'curriculum_owner_name_version_unique');
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('curriculum_package_courses')) {
            Schema::create('curriculum_package_courses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('curriculum_package_id')->constrained()->restrictOnDelete();
                $table->foreignId('course_id')->constrained()->restrictOnDelete();
                $table->foreignId('grade_level_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('grade_context_key', 30)->default('all');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('required')->default(true);
                $table->timestamps();
                $table->unique(
                    ['curriculum_package_id', 'course_id', 'grade_context_key'],
                    'curriculum_course_grade_unique',
                );
                $table->index(['curriculum_package_id', 'sort_order'], 'curriculum_mapping_sort_index');
            });
        }

        if (! Schema::hasIndex('curriculum_package_courses', 'curriculum_mapping_sort_index')) {
            Schema::table('curriculum_package_courses', function (Blueprint $table) {
                $table->index(['curriculum_package_id', 'sort_order'], 'curriculum_mapping_sort_index');
            });
        }

        if (! Schema::hasTable('academic_year_configurations')) {
            Schema::create('academic_year_configurations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
                $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
                $table->foreignId('education_provider_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('calendar_profile_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('standards_framework_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('curriculum_package_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('status', 20)->default('draft');
                $table->text('notes')->nullable();
                $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('configured_at')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'school_year_id']);
                $table->index(['tenant_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_year_configurations');
        Schema::dropIfExists('curriculum_package_courses');
        Schema::dropIfExists('curriculum_packages');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('calendar_profiles');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('standards_frameworks');
        Schema::dropIfExists('education_providers');
    }
};
