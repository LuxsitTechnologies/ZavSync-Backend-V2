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
        Schema::create('employee_expense_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id');
            $table->foreignUuid('category_id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('PKR');
            $table->date('expense_date');
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('receipt_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->char('receipt_request_key_hash', 64)->nullable();
            $table->char('receipt_payload_hash', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('create_request_key_hash', 64)->nullable();
            $table->char('create_payload_hash', 64)->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'employee_id'], 'expense_claims_employee_company_fk')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'category_id'], 'expense_claims_category_company_fk')
                ->references(['company_id', 'id'])->on('employee_expense_categories')->restrictOnDelete();
            $table->unique(['company_id', 'employee_id', 'create_request_key_hash'], 'expense_claims_create_key');
            $table->index(['company_id', 'employee_id', 'status'], 'expense_claims_employee_status');
        });
        Schema::create('employee_expense_claim_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_expense_claim_id')->constrained('employee_expense_claims', indexName: 'expense_claim_events_claim_fk')->cascadeOnDelete();
            $table->string('event_type', 30);
            $table->unsignedInteger('claim_version');
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->constrained('users');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['employee_expense_claim_id', 'claim_version'], 'expense_claim_events_version_uniq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_expense_claim_events');
        Schema::dropIfExists('employee_expense_claims');
    }
};
