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
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->string('bank_name')->nullable();
            $table->string('account_title')->nullable();
            $table->string('masked_account_number', 80)->nullable();
            $table->string('iban', 40)->nullable();
            $table->char('currency', 3);
            $table->foreignUuid('gl_account_id')->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('opening_balance')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'gl_account_id']);
            $table->unique(['company_id', 'iban']);
            $table->index(['company_id', 'type', 'is_active']);
            $table->index(['company_id', 'is_default']);
        });

        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('financial_account_id')->constrained()->restrictOnDelete();
            $table->string('original_filename');
            $table->char('file_hash', 64);
            $table->string('statement_reference')->nullable();
            $table->date('statement_start_date')->nullable();
            $table->date('statement_end_date')->nullable();
            $table->bigInteger('opening_balance')->nullable();
            $table->bigInteger('closing_balance')->nullable();
            $table->string('status', 20)->default('preview');
            $table->json('column_mapping');
            $table->json('preview_rows');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->string('idempotency_key', 120);
            $table->char('idempotency_hash', 64);
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'financial_account_id', 'file_hash']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'financial_account_id', 'status']);
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('bank_statement_import_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('statement_row')->nullable();
            $table->string('evidence_type', 24)->default('statement');
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            $table->text('description');
            $table->string('bank_reference')->nullable();
            $table->string('external_transaction_id')->nullable();
            $table->string('direction', 10);
            $table->unsignedBigInteger('amount');
            $table->bigInteger('running_balance')->nullable();
            $table->char('currency', 3);
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_account')->nullable();
            $table->char('fingerprint', 64);
            $table->string('status', 24)->default('unmatched');
            $table->foreignUuid('classification_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->string('origin_idempotency_key', 120)->nullable();
            $table->char('origin_idempotency_hash', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'financial_account_id', 'external_transaction_id'], 'bank_transaction_external_unique');
            $table->unique(['company_id', 'financial_account_id', 'fingerprint'], 'bank_transaction_fingerprint_unique');
            $table->unique(['company_id', 'origin_idempotency_key']);
            $table->index(['company_id', 'financial_account_id', 'transaction_date'], 'bank_transaction_ledger_index');
            $table->index(['company_id', 'financial_account_id', 'status'], 'bank_transaction_status_index');
        });

        Schema::create('internal_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('number', 40);
            $table->foreignUuid('source_financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignUuid('destination_financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->date('transfer_date');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('journal_id')->constrained('journals')->restrictOnDelete();
            $table->string('idempotency_key', 120);
            $table->char('idempotency_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'sequence']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'transfer_date']);
        });

        Schema::create('gateway_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('provider', 80);
            $table->string('settlement_reference', 120);
            $table->date('settlement_date');
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('fee_amount');
            $table->bigInteger('adjustment_amount')->default(0);
            $table->unsignedBigInteger('net_amount');
            $table->char('currency', 3);
            $table->foreignUuid('destination_financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignUuid('clearing_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignUuid('fee_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->foreignUuid('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->string('idempotency_key', 120);
            $table->char('idempotency_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'provider', 'settlement_reference']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'settlement_date', 'status']);
        });

        Schema::create('gateway_settlement_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('gateway_settlement_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 60);
            $table->uuid('source_id');
            $table->unsignedBigInteger('amount');
            $table->timestamps();
            $table->unique(['gateway_settlement_id', 'source_type', 'source_id'], 'gateway_settlement_allocation_unique');
            $table->index(['company_id', 'source_type', 'source_id']);
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('number', 40);
            $table->foreignUuid('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('bank_statement_import_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->bigInteger('statement_opening_balance');
            $table->bigInteger('statement_closing_balance');
            $table->bigInteger('book_balance');
            $table->bigInteger('difference');
            $table->string('status', 20)->default('draft');
            $table->string('idempotency_key', 120);
            $table->char('idempotency_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'sequence']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'financial_account_id', 'period_end'], 'bank_reconciliation_period_index');
        });

        Schema::create('bank_reconciliation_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('bank_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('bank_reconciliation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('matchable_type', 60);
            $table->uuid('matchable_id');
            $table->unsignedBigInteger('amount');
            $table->string('confidence', 20)->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('idempotency_key', 120);
            $table->char('idempotency_hash', 64);
            $table->foreignId('matched_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('matched_at');
            $table->foreignId('unmatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unmatched_at')->nullable();
            $table->text('unmatch_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'bank_transaction_id', 'status'], 'bank_match_transaction_index');
            $table->index(['company_id', 'matchable_type', 'matchable_id', 'status'], 'bank_match_source_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_matches');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('gateway_settlement_allocations');
        Schema::dropIfExists('gateway_settlements');
        Schema::dropIfExists('internal_transfers');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_statement_imports');
        Schema::dropIfExists('financial_accounts');
    }
};
