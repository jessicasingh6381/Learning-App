<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_parser_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_source_file_id')->constrained()->cascadeOnDelete();
            $table->string('file_checksum', 64);
            $table->string('registry_signature', 64);
            $table->string('state', 20);
            $table->string('parser_key', 80)->nullable();
            $table->string('parser_version', 50)->nullable();
            $table->string('extraction_method', 50)->nullable();
            $table->decimal('recognition_score', 5, 4)->nullable();
            $table->string('document_family', 100)->nullable();
            $table->string('user_message', 500);
            $table->text('internal_diagnostic')->nullable();
            $table->json('candidate_parsers')->nullable();
            $table->timestamp('assessed_at');
            $table->timestamps();
            $table->unique(['academic_source_file_id', 'registry_signature'], 'curriculum_capability_file_registry_unique');
            $table->index(['tenant_id', 'state']);
            $table->index(['academic_source_id', 'assessed_at'], 'curriculum_capability_source_assessed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_parser_capabilities');
    }
};
