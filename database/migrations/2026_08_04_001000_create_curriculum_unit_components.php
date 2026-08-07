<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_import_proposals', function (Blueprint $table) {
            $table->string('component_type', 40)->nullable()->after('unit_type');
            $table->text('description')->nullable()->after('name');
        });

        Schema::create('curriculum_unit_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_unit_id')->constrained(indexName: 'curriculum_component_unit_fk')->restrictOnDelete();
            $table->foreignId('parent_component_id')->nullable()->constrained('curriculum_unit_components', indexName: 'curriculum_component_parent_fk')->restrictOnDelete();
            $table->string('component_type', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sequence');
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('academic_source_id')->constrained(indexName: 'curriculum_component_source_fk')->restrictOnDelete();
            $table->foreignId('academic_source_file_id')->constrained(indexName: 'curriculum_component_file_fk')->restrictOnDelete();
            $table->foreignId('curriculum_import_id')->constrained(indexName: 'curriculum_component_import_fk')->restrictOnDelete();
            $table->foreignId('curriculum_import_proposal_id')->constrained(indexName: 'curriculum_component_proposal_fk')->restrictOnDelete();
            $table->unsignedInteger('source_page')->nullable();
            $table->text('source_raw_text')->nullable();
            $table->string('parser_key', 80);
            $table->string('parser_version', 50);
            $table->decimal('source_confidence', 4, 3)->nullable();
            $table->text('source_note')->nullable();
            $table->timestamps();
            $table->unique('curriculum_import_proposal_id', 'curriculum_component_proposal_unique');
            $table->index(['tenant_id', 'component_type'], 'curriculum_component_tenant_type_idx');
            $table->index(['curriculum_unit_id', 'parent_component_id', 'sequence'], 'curriculum_component_tree_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_unit_components');
        Schema::table('curriculum_import_proposals', function (Blueprint $table) {
            $table->dropColumn(['component_type', 'description']);
        });
    }
};
