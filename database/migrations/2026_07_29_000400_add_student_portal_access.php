<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('username', 40)->nullable()->unique()->after('email');
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->timestamp('last_login_at')->nullable()->after('must_change_password');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->timestamp('student_access_enabled_at')->nullable()->after('user_id');
            $table->unique('user_id', 'students_user_id_unique');
            $table->foreign(['tenant_id', 'user_id'], 'students_tenant_user_membership_foreign')
                ->references(['tenant_id', 'user_id'])
                ->on('tenant_memberships')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign('students_tenant_user_membership_foreign');
            $table->dropUnique('students_user_id_unique');
            $table->dropColumn('student_access_enabled_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'must_change_password', 'last_login_at']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
