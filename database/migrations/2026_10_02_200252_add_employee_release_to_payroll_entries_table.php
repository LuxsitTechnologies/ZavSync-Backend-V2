<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['company_id', 'employee_id', 'released_at', 'id'], 'payroll_self_history_index');
        });

        $time = now();
        DB::table('permissions')->insertOrIgnore([
            ['name' => 'employee.payroll.view', 'description' => 'Read only the linked employee\'s released payslips.', 'created_at' => $time, 'updated_at' => $time],
            ['name' => 'payroll.release', 'description' => 'Release a posted payroll entry to its employee.', 'created_at' => $time, 'updated_at' => $time],
        ]);
    }

    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropIndex('payroll_self_history_index');
            $table->dropForeign(['released_by']);
            $table->dropColumn(['released_at', 'released_by']);
        });
    }
};
