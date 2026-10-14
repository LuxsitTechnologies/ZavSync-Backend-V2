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
        Schema::create('pakistan_fbr_invoice_reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('invoice_id')->constrained('pakistan_fbr_invoices')->restrictOnDelete();
            $table->foreignUuid('attempt_id')->constrained('pakistan_fbr_submission_attempts')->restrictOnDelete();
            $key = $table->string('idempotency_key', 100);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $key->charset('utf8mb4')->collation('utf8mb4_nopad_bin');
            }
            $table->string('payload_hash', 64);
            $table->string('previous_status', 30);
            $table->string('resolved_status', 30);
            $table->string('fbr_reference_number', 255)->nullable();
            $table->text('evidence_note');
            $table->foreignId('resolved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['company_id', 'idempotency_key'], 'pk_fbr_invoice_reconcile_key_unique');
            $table->index(['company_id', 'invoice_id'], 'pk_fbr_invoice_reconcile_invoice_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pakistan_fbr_invoice_reconciliations');
    }
};
