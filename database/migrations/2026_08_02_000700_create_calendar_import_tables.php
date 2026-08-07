<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_source_file_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('calendar_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('extracting');
            $table->string('extraction_method', 50)->default('pdf_text');
            $table->string('parser_version', 50);
            $table->text('diagnostic')->nullable();
            $table->date('proposed_first_day')->nullable();
            $table->date('proposed_last_day')->nullable();
            $table->boolean('update_school_year_dates')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['academic_source_id', 'created_at']);
        });

        Schema::create('calendar_import_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_import_id')->constrained()->cascadeOnDelete();
            $table->date('event_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('name');
            $table->string('event_type', 40);
            $table->string('instructional_effect', 30);
            $table->decimal('confidence', 4, 3)->nullable();
            $table->unsignedInteger('source_page')->nullable();
            $table->text('raw_text')->nullable();
            $table->text('parser_note')->nullable();
            $table->boolean('included')->default(true);
            $table->boolean('manually_edited')->default(false);
            $table->timestamps();
            $table->index(['calendar_import_id', 'event_date']);
        });

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->foreignId('calendar_import_id')->nullable()->after('calendar_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('calendar_import_proposal_id')->nullable()->after('calendar_import_id')->constrained()->restrictOnDelete();
            $table->index(['calendar_import_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropForeign(['calendar_import_proposal_id']);
            $table->dropForeign(['calendar_import_id']);
            $table->dropIndex(['calendar_import_id', 'status']);
            $table->dropColumn(['calendar_import_proposal_id', 'calendar_import_id']);
        });
        Schema::dropIfExists('calendar_import_proposals');
        Schema::dropIfExists('calendar_imports');
    }
};
