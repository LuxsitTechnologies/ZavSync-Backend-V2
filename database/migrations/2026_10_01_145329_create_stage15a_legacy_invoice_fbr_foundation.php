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
        Schema::create('legacy_import_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_system', 60);
            $table->string('source_fingerprint', 64);
            $table->string('source_company_id', 120);
            $table->string('status', 30)->default('PENDING');
            $table->string('mode', 20)->default('IMPORT');
            $table->string('source_filename')->nullable();
            $table->json('source_manifest')->nullable();
            $table->json('progress')->nullable();
            $table->json('reconciliation')->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['source_system', 'source_fingerprint', 'company_id']);
            $table->index(['company_id', 'status', 'created_at']);
        });

        Schema::create('legacy_entity_maps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_run_id')->constrained('legacy_import_runs')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('source_system', 60);
            $table->string('source_entity_type', 60);
            $table->string('source_id', 120);
            $table->string('target_entity_type', 120)->nullable();
            $table->string('target_id', 120)->nullable();
            $table->json('safe_metadata')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'source_system', 'source_entity_type', 'source_id'], 'legacy_entity_maps_source_unique');
            $table->unique(['source_system', 'source_entity_type', 'source_id'], 'legacy_entity_maps_global_source_unique');
            $table->index(['import_run_id', 'source_entity_type']);
        });

        Schema::create('migration_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_run_id')->constrained('legacy_import_runs')->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_entity_type', 60);
            $table->string('source_id', 120)->nullable();
            $table->string('target_id', 120)->nullable();
            $table->string('exception_code', 80);
            $table->string('severity', 20)->default('ERROR');
            $table->json('safe_metadata')->nullable();
            $table->string('resolution_state', 30)->default('OPEN');
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'resolution_state', 'severity']);
            $table->index(['import_run_id', 'exception_code']);
        });

        Schema::create('fbr_company_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('seller_tax_identifier', 30);
            $table->string('seller_business_name');
            $table->string('seller_province', 100);
            $table->text('seller_address');
            $table->string('environment', 20)->default('SANDBOX');
            $table->text('credential')->nullable();
            $table->string('connection_state', 30)->default('NOT_VERIFIED');
            $table->timestamp('last_verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('fbr_reference_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('category', 60);
            $table->string('code', 120);
            $table->string('label');
            $table->string('parent_code', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->string('source', 60)->default('LOCAL_FIXTURE');
            $table->string('source_version', 60);
            $table->boolean('is_active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->unique(['category', 'code', 'source_version']);
            $table->index(['category', 'is_active', 'code']);
        });

        Schema::create('pakistan_fbr_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_historical')->default(false);
            $table->string('document_state', 30)->default('DRAFT');
            $table->foreignUuid('legacy_import_run_id')->nullable()->constrained('legacy_import_runs')->restrictOnDelete();
            $table->string('legacy_source_system', 60)->nullable();
            $table->string('legacy_source_id', 120)->nullable();
            $table->string('legacy_original_company_id', 120)->nullable();
            $table->string('legacy_status', 40)->nullable();
            $table->string('historical_accounting_state', 40)->nullable();
            $table->string('migration_reconciliation_state', 40)->nullable();
            $table->json('buyer_snapshot');
            $table->json('legacy_original_timestamps')->nullable();
            $table->json('legacy_original_financial_values')->nullable();
            $table->unsignedBigInteger('sequence')->nullable();
            $table->string('invoice_number', 120);
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('invoice_type', 120)->nullable();
            $table->string('sale_type', 120)->nullable();
            $table->string('origin_province', 100)->nullable();
            $table->string('destination_province', 100)->nullable();
            $table->string('currency', 3)->default('PKR');
            foreach (['subtotal', 'discount', 'taxable_amount', 'sales_tax', 'other_tax', 'advance_tax', 'withholding_tax', 'total', 'amount_paid'] as $column) {
                $table->bigInteger($column)->default(0);
            }
            $table->text('notes')->nullable();
            $table->string('fbr_status', 30)->default('not_submitted');
            $table->string('fbr_reference_number')->nullable();
            $table->json('fbr_response_metadata')->nullable();
            $table->string('creation_idempotency_key', 100)->nullable();
            $table->string('creation_idempotency_hash', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'invoice_number'], 'pk_fbr_invoice_number_unique');
            $table->unique(['company_id', 'sequence'], 'pk_fbr_invoice_sequence_unique');
            $table->unique(['company_id', 'creation_idempotency_key'], 'pk_fbr_creation_key_unique');
            $table->unique(['company_id', 'legacy_source_system', 'legacy_source_id'], 'pk_fbr_legacy_source_unique');
            $table->index(['company_id', 'is_historical', 'invoice_date'], 'pk_fbr_invoice_date_index');
        });

        Schema::create('pakistan_fbr_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('pakistan_fbr_invoices')->restrictOnDelete();
            $table->string('legacy_source_id', 120)->nullable();
            $table->unsignedInteger('position');
            $table->text('description');
            $table->string('hs_code', 60)->nullable();
            $table->bigInteger('quantity_milli');
            $table->string('unit', 60);
            foreach (['unit_price', 'subtotal', 'discount', 'taxable_amount', 'tax_amount', 'other_tax_amount', 'advance_tax_amount', 'withholding_tax_amount', 'total'] as $column) {
                $table->bigInteger($column)->default(0);
            }
            foreach (['tax_rate_bps', 'other_tax_rate_bps', 'advance_tax_rate_bps', 'withholding_tax_rate_bps'] as $column) {
                $table->unsignedInteger($column)->default(0);
            }
            $table->string('fbr_rate_id', 120)->nullable();
            $table->string('sro_schedule_id', 120)->nullable();
            $table->string('sro_item_id', 120)->nullable();
            $table->string('sales_type', 120)->nullable();
            $table->json('legacy_original_values')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'legacy_source_id'], 'pk_fbr_line_source_unique');
        });

        Schema::create('pakistan_fbr_submission_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('invoice_id')->constrained('pakistan_fbr_invoices')->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('payload_hash', 64);
            $table->string('status', 30);
            $table->json('request_metadata')->nullable();
            $table->json('response_metadata')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'idempotency_key'], 'pk_fbr_submission_key_unique');
        });

        Schema::create('legacy_fbr_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('invoice_id')->constrained('pakistan_fbr_invoices')->restrictOnDelete();
            $table->foreignUuid('import_run_id')->constrained('legacy_import_runs')->cascadeOnDelete();
            $table->string('source_system', 60);
            $table->string('source_id', 120);
            $table->string('original_status', 40);
            $table->string('normalized_status', 40);
            $table->string('fbr_reference_number')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->json('original_timestamps')->nullable();
            $table->json('sanitized_response')->nullable();
            $table->boolean('requires_review')->default(false);
            $table->boolean('submission_blocked')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'source_system', 'source_id']);
            $table->index(['company_id', 'fbr_reference_number']);
            $table->index(['company_id', 'normalized_status', 'requires_review']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legacy_fbr_evidence');

        Schema::dropIfExists('pakistan_fbr_submission_attempts');
        Schema::dropIfExists('pakistan_fbr_invoice_lines');
        Schema::dropIfExists('pakistan_fbr_invoices');

        Schema::dropIfExists('fbr_reference_values');
        Schema::dropIfExists('fbr_company_configurations');
        Schema::dropIfExists('migration_exceptions');
        Schema::dropIfExists('legacy_entity_maps');
        Schema::dropIfExists('legacy_import_runs');
    }
};
