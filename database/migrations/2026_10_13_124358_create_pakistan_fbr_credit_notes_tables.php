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
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedBigInteger('fbr_credit_note_sequence')->default(0);
        });

        Schema::create('pakistan_fbr_credit_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('invoice_id')->constrained('pakistan_fbr_invoices')->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $number = $table->string('credit_note_number', 120);
            $key = $table->string('idempotency_key', 100);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $number->charset('utf8mb4')->collation('utf8mb4_nopad_bin');
                $key->charset('utf8mb4')->collation('utf8mb4_nopad_bin');
            }
            $table->string('payload_hash', 64);
            $table->string('state', 30)->default('DRAFT');
            $table->date('issued_on');
            $table->text('reason');
            $table->string('original_invoice_number', 120);
            $table->string('original_fbr_reference_number', 255);
            $table->json('original_fbr_evidence')->nullable();
            $table->json('seller_snapshot')->nullable();
            $table->json('buyer_snapshot')->nullable();
            $table->json('portal_snapshot')->nullable();
            $table->string('fbr_reference_number', 255)->nullable();
            $table->json('fbr_response_metadata')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            foreach (['taxable_amount', 'sales_tax', 'extra_tax', 'further_tax', 'st_withheld', 'total'] as $column) {
                $table->bigInteger($column);
            }
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'sequence'], 'pk_fbr_credit_sequence_unique');
            $table->unique(['company_id', 'credit_note_number'], 'pk_fbr_credit_number_unique');
            $table->unique(['company_id', 'idempotency_key'], 'pk_fbr_credit_key_unique');
            $table->unique('invoice_id', 'pk_fbr_credit_one_per_invoice');
            $table->index(['company_id', 'invoice_id'], 'pk_fbr_credit_invoice_index');
        });

        Schema::create('pakistan_fbr_credit_note_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('credit_note_id')->constrained('pakistan_fbr_credit_notes')->restrictOnDelete();
            $table->foreignUuid('invoice_line_id')->constrained('pakistan_fbr_invoice_lines')->restrictOnDelete();
            $table->json('source_snapshot');
            foreach (['taxable_amount', 'sales_tax', 'extra_tax', 'further_tax', 'st_withheld', 'total'] as $column) {
                $table->bigInteger($column);
            }
            $table->timestamps();
            $table->unique(['credit_note_id', 'invoice_line_id'], 'pk_fbr_credit_line_unique');
            $table->index('invoice_line_id', 'pk_fbr_credit_source_line_index');
        });

        Schema::create('pakistan_fbr_credit_note_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('credit_note_id')->constrained('pakistan_fbr_credit_notes')->restrictOnDelete();
            $key = $table->string('idempotency_key', 100);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $key->charset('utf8mb4')->collation('utf8mb4_nopad_bin');
            }
            $table->string('payload_hash', 64);
            $table->unsignedBigInteger('claim_generation')->default(0);
            $table->string('status', 30);
            $table->string('reference_number', 255)->nullable();
            $table->json('response_metadata')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key'], 'pk_fbr_credit_attempt_key_unique');
            $table->index(['company_id', 'credit_note_id', 'status'], 'pk_fbr_credit_attempt_status_index');
        });

        Schema::create('pakistan_fbr_credit_note_reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('credit_note_id')->constrained('pakistan_fbr_credit_notes')->restrictOnDelete();
            $key = $table->string('idempotency_key', 100);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $key->charset('utf8mb4')->collation('utf8mb4_nopad_bin');
            }
            $table->string('payload_hash', 64);
            $table->string('previous_state', 30);
            $table->string('resolved_state', 30);
            $table->string('fbr_reference_number', 255)->nullable();
            $table->text('evidence_note');
            $table->foreignId('resolved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['company_id', 'credit_note_id'], 'pk_fbr_credit_reconcile_index');
            $table->unique(['company_id', 'idempotency_key'], 'pk_fbr_credit_reconcile_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pakistan_fbr_credit_note_reconciliations');
        Schema::dropIfExists('pakistan_fbr_credit_note_attempts');
        Schema::dropIfExists('pakistan_fbr_credit_note_lines');
        Schema::dropIfExists('pakistan_fbr_credit_notes');
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('fbr_credit_note_sequence');
        });
    }
};
