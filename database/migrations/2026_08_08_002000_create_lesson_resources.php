<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('resource_type', 50);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('delivery_type', 30);
            $table->string('availability_status', 30)->default('not_applicable');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('asset_disk', 50)->nullable();
            $table->string('asset_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('generated_by', 100)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'lesson_resources_tenant_id_id_unique');
            $table->unique(['lesson_id', 'category', 'sort_order'], 'lesson_resources_category_order_unique');
            $table->foreign(['tenant_id', 'lesson_id'], 'lesson_resources_tenant_lesson_fk')
                ->references(['tenant_id', 'id'])->on('lessons')->cascadeOnDelete();
            $table->index(['lesson_id', 'category', 'availability_status'], 'lesson_resources_display_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_resources');
    }
};
