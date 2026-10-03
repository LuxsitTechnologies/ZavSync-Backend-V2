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
        Schema::create('employee_expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->char('create_request_key_hash', 64)->nullable();
            $table->char('create_payload_hash', 64)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'expense_categories_company_id_identity_uniq');
            $table->unique(['company_id', 'name'], 'expense_categories_company_name_uniq');
            $table->unique(['company_id', 'created_by', 'create_request_key_hash'], 'expense_categories_create_key');
        });
        $now = now();
        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $name): array => ['name' => $name, 'description' => 'Employee expense claim access.', 'created_at' => $now, 'updated_at' => $now],
            ['employee.expenses.view', 'employee.expenses.create', 'employee.expenses.edit', 'employee.expenses.submit',
                'expenses.view', 'expenses.categories.manage', 'expenses.approve'],
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_expense_categories');
    }
};
