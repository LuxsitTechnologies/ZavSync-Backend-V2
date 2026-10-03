<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->char('employee_upload_request_key_hash', 64)->nullable();
            $table->char('employee_upload_payload_hash', 64)->nullable();
            $table->timestamp('employee_released_at')->nullable();
            $table->foreignId('employee_released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['company_id', 'uploaded_by', 'employee_upload_request_key_hash'], 'documents_employee_upload_key');
            $table->index(['company_id', 'documentable_type', 'documentable_id', 'employee_released_at'], 'documents_employee_release_lookup');
        });
        $now = now();
        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $name): array => ['name' => $name, 'description' => 'Employee personal document access.', 'created_at' => $now, 'updated_at' => $now],
            ['employee.documents.view', 'employee.documents.upload', 'employee.documents.admin.view', 'employee.documents.issue', 'employee.documents.release'],
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_employee_upload_key');
            $table->dropIndex('documents_employee_release_lookup');
            $table->dropForeign(['employee_released_by']);
            $table->dropColumn(['employee_upload_request_key_hash', 'employee_upload_payload_hash', 'employee_released_at', 'employee_released_by']);
        });
    }
};
