<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->string('fulfillment_strategy', 50)->nullable()->after('availability_status');
            $table->string('fulfillment_provider', 100)->nullable()->after('fulfillment_strategy');
            $table->text('source_url')->nullable()->after('fulfillment_provider');
            $table->text('source_attribution')->nullable()->after('source_url');
            $table->string('license_name')->nullable()->after('source_attribution');
            $table->text('license_url')->nullable()->after('license_name');
            $table->char('checksum_sha256', 64)->nullable()->after('mime_type');
            $table->unsignedBigInteger('file_size')->nullable()->after('checksum_sha256');
            $table->timestamp('fulfillment_attempted_at')->nullable()->after('generated_at');
            $table->text('fulfillment_error')->nullable()->after('fulfillment_attempted_at');
            $table->json('validation_metadata')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->dropColumn([
                'fulfillment_strategy', 'fulfillment_provider', 'source_url', 'source_attribution',
                'license_name', 'license_url', 'checksum_sha256', 'file_size',
                'fulfillment_attempted_at', 'fulfillment_error', 'validation_metadata',
            ]);
        });
    }
};
