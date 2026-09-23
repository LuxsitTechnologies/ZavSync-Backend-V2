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
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('invoice_number', 40);
            $table->date('invoice_date');
            $table->date('due_date');
            $table->char('currency', 3)->default('PKR');
            $table->string('status', 24)->default('draft');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('taxable_amount')->default(0);
            $table->unsignedBigInteger('sales_tax')->default(0);
            $table->unsignedBigInteger('other_tax')->default(0);
            $table->unsignedBigInteger('advance_tax')->default(0);
            $table->unsignedBigInteger('withholding_tax')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->unsignedBigInteger('balance_due')->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->string('fbr_status', 24)->default('not_submitted');
            $table->string('fbr_reference_number')->nullable();
            $table->json('fbr_response_metadata')->nullable();
            $table->string('creation_idempotency_key', 100)->nullable();
            $table->char('creation_idempotency_hash', 64)->nullable();
            $table->foreignUuid('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->foreignUuid('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'sequence']);
            $table->unique(['company_id', 'invoice_number']);
            $table->unique(['company_id', 'creation_idempotency_key']);
            $table->index(['company_id', 'status', 'invoice_date']);
            $table->index(['company_id', 'customer_id', 'due_date']);
            $table->index(['company_id', 'fbr_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
