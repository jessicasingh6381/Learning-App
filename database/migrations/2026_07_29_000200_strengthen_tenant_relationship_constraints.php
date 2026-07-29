<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'students_tenant_id_id_unique');
        });
        Schema::table('school_years', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'school_years_tenant_id_id_unique');
        });
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'student_id'], 'enrollments_tenant_student_foreign')
                ->references(['tenant_id', 'id'])->on('students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'school_year_id'], 'enrollments_tenant_school_year_foreign')
                ->references(['tenant_id', 'id'])->on('school_years')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->dropForeign('enrollments_tenant_student_foreign');
            $table->dropForeign('enrollments_tenant_school_year_foreign');
        });
        Schema::table('school_years', function (Blueprint $table) {
            $table->dropUnique('school_years_tenant_id_id_unique');
        });
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_tenant_id_id_unique');
        });
    }
};
