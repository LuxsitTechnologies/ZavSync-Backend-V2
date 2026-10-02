<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unique(['company_id', 'id'], 'employees_company_id_identity_uniq');
        });

        Schema::table('company_users', function (Blueprint $table) {
            $table->foreignUuid('employee_id')->nullable();
            $table->unique('employee_id', 'company_users_employee_identity_uniq');
            $table->foreign(['company_id', 'employee_id'])
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_users', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'employee_id']);
            $table->dropUnique('company_users_employee_identity_uniq');
            $table->dropColumn('employee_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_company_id_identity_uniq');
        });
    }
};
