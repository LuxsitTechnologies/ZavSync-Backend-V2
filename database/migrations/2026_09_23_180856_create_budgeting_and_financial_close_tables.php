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
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->char('currency', 3);
            $table->string('status', 12)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'start_date', 'end_date']);
        });

        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->foreignUuid('fiscal_year_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            $table->index(['fiscal_year_id', 'start_date']);
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('based_on_budget_id')->nullable()->constrained('budgets')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 12)->default('draft');
            $table->char('currency', 3);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'fiscal_year_id', 'name', 'version']);
            $table->index(['company_id', 'fiscal_year_id', 'status', 'is_active']);
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('accounting_period_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->timestamps();
            $table->unique(['budget_id', 'account_id', 'accounting_period_id']);
            $table->index(['company_id', 'accounting_period_id', 'account_id']);
        });

        Schema::create('forecasts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('based_on_budget_id')->nullable()->constrained('budgets')->nullOnDelete();
            $table->foreignUuid('based_on_forecast_id')->nullable()->constrained('forecasts')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 12)->default('draft');
            $table->char('currency', 3);
            $table->date('actuals_through')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'fiscal_year_id', 'name', 'version']);
            $table->index(['company_id', 'fiscal_year_id', 'status', 'is_active']);
        });

        Schema::create('forecast_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('forecast_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('accounting_period_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->timestamps();
            $table->unique(['forecast_id', 'account_id', 'accounting_period_id'], 'fl_forecast_account_period_uq');
            $table->index(['company_id', 'accounting_period_id', 'account_id']);
        });

        Schema::create('accounting_close_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('close_type', 12);
            $table->foreignUuid('accounting_period_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('fiscal_year_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12)->default('closed');
            $table->json('checklist_snapshot');
            $table->text('reason')->nullable();
            $table->foreignUuid('closing_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignUuid('reversal_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->string('idempotency_key')->nullable();
            $table->string('idempotency_hash', 64)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'close_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_close_records');
        Schema::dropIfExists('forecast_lines');
        Schema::dropIfExists('forecasts');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fiscal_year_id');
        });
        Schema::dropIfExists('fiscal_years');
    }
};
