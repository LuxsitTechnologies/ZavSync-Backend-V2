<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('employee_code', 50);
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('department')->nullable();
            $table->string('designation')->nullable();
            $table->string('employment_type', 30)->default('full_time');
            $table->string('status', 30)->default('active');
            $table->date('joining_date');
            $table->date('leaving_date')->nullable();
            $table->string('location')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'employee_code']);
            $table->unique(['company_id', 'email']);
            $table->index(['company_id', 'status', 'joining_date']);
        });

        Schema::create('payroll_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('type', 40);
            $table->string('calculation_method', 30)->default('fixed');
            $table->unsignedBigInteger('fixed_amount')->nullable();
            $table->unsignedInteger('rate_bps')->nullable();
            $table->string('calculation_base', 30)->nullable();
            $table->boolean('is_taxable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignUuid('gl_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignUuid('liability_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type', 'is_active']);
        });

        Schema::create('employee_payroll_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained()->restrictOnDelete();
            $table->string('payroll_status', 20)->default('active');
            $table->string('pay_frequency', 20)->default('monthly');
            $table->unsignedBigInteger('base_salary');
            $table->char('currency', 3);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('tax_identifier', 30)->nullable();
            $table->json('statutory_registration')->nullable();
            $table->foreignUuid('payment_financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->string('employee_bank_reference')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'effective_from']);
            $table->index(['company_id', 'pay_frequency', 'payroll_status', 'effective_from'], 'payroll_profile_effective_index');
        });

        Schema::create('employee_payroll_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('employee_payroll_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payroll_component_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('fixed_amount')->nullable();
            $table->unsignedInteger('rate_bps')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['employee_payroll_profile_id', 'payroll_component_id'], 'employee_payroll_component_unique');
            $table->index(['company_id', 'payroll_component_id'], 'epc_company_component_idx');
        });

        Schema::create('payroll_statutory_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('payroll_component_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('jurisdiction', 80);
            $table->string('rule_type', 50);
            $table->string('version', 40);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('threshold_from')->default(0);
            $table->unsignedBigInteger('threshold_to')->nullable();
            $table->unsignedInteger('rate_bps')->default(0);
            $table->unsignedBigInteger('fixed_amount')->default(0);
            $table->unsignedBigInteger('minimum_amount')->nullable();
            $table->unsignedBigInteger('maximum_amount')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'jurisdiction', 'rule_type', 'version'], 'payroll_statutory_rule_version_unique');
            $table->index(['company_id', 'rule_type', 'effective_from'], 'payroll_statutory_effective_index');
        });

        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('fiscal_year_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('accounting_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('frequency', 20)->default('monthly');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date');
            $table->string('status', 20)->default('open');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'frequency', 'period_start', 'period_end'], 'payroll_period_unique');
            $table->index(['company_id', 'status', 'pay_date']);
        });

        Schema::create('payroll_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payroll_period_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('number', 50);
            $table->string('status', 30)->default('draft');
            $table->date('accounting_date');
            $table->unsignedInteger('employee_count')->default(0);
            $table->unsignedBigInteger('gross_earnings')->default(0);
            $table->unsignedBigInteger('taxable_earnings')->default(0);
            $table->unsignedBigInteger('employee_deductions')->default(0);
            $table->unsignedBigInteger('employee_contributions')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('employer_contributions')->default(0);
            $table->unsignedBigInteger('reimbursements')->default(0);
            $table->unsignedBigInteger('net_pay')->default(0);
            $table->unsignedBigInteger('employer_total_cost')->default(0);
            $table->foreignUuid('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->foreignUuid('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->foreignUuid('correction_of_batch_id')->nullable()->constrained('payroll_batches')->restrictOnDelete();
            $table->text('correction_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->string('posting_idempotency_key', 120)->nullable();
            $table->char('posting_idempotency_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'sequence']);
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'payroll_period_id']);
            $table->unique(['company_id', 'correction_of_batch_id']);
            $table->unique(['company_id', 'posting_idempotency_key']);
            $table->index(['company_id', 'status', 'accounting_date']);
        });

        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payroll_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('employee_payroll_profile_id')->constrained()->restrictOnDelete();
            $table->string('employee_code', 50);
            $table->string('employee_name');
            $table->string('department')->nullable();
            $table->string('designation')->nullable();
            $table->unsignedBigInteger('base_salary');
            $table->char('currency', 3);
            $table->json('profile_snapshot');
            $table->json('statutory_rule_snapshot')->nullable();
            $table->unsignedBigInteger('gross_earnings')->default(0);
            $table->unsignedBigInteger('taxable_earnings')->default(0);
            $table->unsignedBigInteger('employee_deductions')->default(0);
            $table->unsignedBigInteger('employee_contributions')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('employer_contributions')->default(0);
            $table->unsignedBigInteger('reimbursements')->default(0);
            $table->unsignedBigInteger('net_pay')->default(0);
            $table->unsignedBigInteger('employer_total_cost')->default(0);
            $table->timestamps();
            $table->unique(['payroll_batch_id', 'employee_id']);
            $table->index(['company_id', 'employee_id']);
        });

        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payroll_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payroll_component_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'payroll_entry_id']);
        });

        Schema::create('payroll_entry_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payroll_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payroll_component_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('payroll_adjustment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('statutory_rule_id')->nullable()->constrained('payroll_statutory_rules')->restrictOnDelete();
            $table->string('component_code', 50);
            $table->string('component_name');
            $table->string('component_type', 40);
            $table->unsignedBigInteger('amount');
            $table->boolean('is_taxable')->default(false);
            $table->foreignUuid('gl_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignUuid('liability_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->json('calculation_snapshot')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'component_type']);
            $table->index(['payroll_entry_id', 'payroll_component_id']);
        });

        Schema::create('payroll_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('number', 50);
            $table->foreignUuid('payroll_batch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('financial_account_id')->constrained()->restrictOnDelete();
            $table->date('payment_date');
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
            $table->index(['company_id', 'payment_date']);
        });

        Schema::create('payroll_payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payroll_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payroll_entry_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamps();
            $table->unique(['payroll_payment_id', 'payroll_entry_id'], 'payroll_payment_entry_unique');
            $table->index(['company_id', 'payroll_entry_id']);
        });

        Schema::create('payroll_liability_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('number', 50);
            $table->foreignUuid('payroll_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('liability_type', 50);
            $table->foreignUuid('financial_account_id')->constrained()->restrictOnDelete();
            $table->date('payment_date');
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
            $table->index(['company_id', 'liability_type', 'payment_date'], 'payroll_liability_settlement_index');
        });

        Schema::create('payroll_liability_settlement_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payroll_liability_settlement_id')->constrained(indexName: 'plsa_settlement_fk')->cascadeOnDelete();
            $table->foreignUuid('payroll_entry_line_id')->constrained(indexName: 'plsa_entry_line_fk')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamps();
            $table->unique(['payroll_liability_settlement_id', 'payroll_entry_line_id'], 'payroll_settlement_line_unique');
            $table->index(['company_id', 'payroll_entry_line_id'], 'payroll_settlement_line_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_liability_settlement_allocations');
        Schema::dropIfExists('payroll_liability_settlements');
        Schema::dropIfExists('payroll_payment_allocations');
        Schema::dropIfExists('payroll_payments');
        Schema::dropIfExists('payroll_entry_lines');
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('payroll_entries');
        Schema::dropIfExists('payroll_batches');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('payroll_statutory_rules');
        Schema::dropIfExists('employee_payroll_components');
        Schema::dropIfExists('employee_payroll_profiles');
        Schema::dropIfExists('payroll_components');
        Schema::dropIfExists('employees');
    }
};
