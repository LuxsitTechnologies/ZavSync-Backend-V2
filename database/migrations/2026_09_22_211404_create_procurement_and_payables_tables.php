<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('code', 30);
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->char('country', 2)->default('PK');
            $table->string('postal_code', 20)->nullable();
            $table->string('ntn', 7)->nullable();
            $table->string('cnic', 13)->nullable();
            $table->string('strn', 30)->nullable();
            $table->string('tax_status', 40)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->char('currency', 3)->default('PKR');
            $table->foreignUuid('default_expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignUuid('default_payable_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'sequence']);
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'ntn']);
            $table->unique(['company_id', 'cnic']);
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('number', 40);
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->char('currency', 3)->default('PKR');
            $table->string('status', 30)->default('draft');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('taxable_amount')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('creation_idempotency_key', 100);
            $table->char('creation_idempotency_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'sequence']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'creation_idempotency_key']);
            $table->index(['company_id', 'status', 'order_date']);
            $table->index(['company_id', 'supplier_id', 'order_date']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->uuid('item_id')->nullable();
            $table->string('item_name')->nullable();
            $table->text('description');
            $table->string('procurement_type', 20)->default('service');
            $table->unsignedBigInteger('quantity_milli');
            $table->string('unit', 30)->default('unit');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('taxable_amount');
            $table->unsignedSmallInteger('tax_rate_bps')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total');
            $table->foreignUuid('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->unsignedBigInteger('received_quantity_milli')->default(0);
            $table->unsignedBigInteger('billed_quantity_milli')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['purchase_order_id', 'position']);
        });

        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('number', 40);
            $table->date('receipt_date');
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 100);
            $table->char('idempotency_hash', 64);
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'sequence']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'purchase_order_id', 'receipt_date']);
        });

        Schema::create('purchase_receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_order_line_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('ordered_quantity_milli');
            $table->unsignedBigInteger('previously_received_quantity_milli');
            $table->unsignedBigInteger('quantity_received_milli');
            $table->unsignedBigInteger('remaining_quantity_milli');
            $table->timestamps();
            $table->unique(['purchase_receipt_id', 'purchase_order_line_id']);
        });

        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('purchase_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('bill_number', 40);
            $table->string('supplier_invoice_number', 100);
            $table->date('bill_date');
            $table->date('posting_date');
            $table->date('due_date');
            $table->char('currency', 3)->default('PKR');
            $table->string('status', 24)->default('draft');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('taxable_amount')->default(0);
            $table->unsignedBigInteger('purchase_tax')->default(0);
            $table->unsignedBigInteger('withholding_tax')->default(0);
            $table->unsignedBigInteger('gross_total')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->unsignedBigInteger('balance_due')->default(0);
            $table->text('notes')->nullable();
            $table->string('creation_idempotency_key', 100);
            $table->char('creation_idempotency_hash', 64);
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
            $table->unique(['company_id', 'bill_number']);
            $table->unique(['company_id', 'supplier_id', 'supplier_invoice_number']);
            $table->unique(['company_id', 'creation_idempotency_key']);
            $table->index(['company_id', 'status', 'posting_date']);
            $table->index(['company_id', 'supplier_id', 'due_date']);
        });

        Schema::create('supplier_bill_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_order_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('purchase_receipt_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->uuid('item_id')->nullable();
            $table->string('item_name')->nullable();
            $table->text('description');
            $table->string('procurement_type', 20)->default('service');
            $table->unsignedBigInteger('quantity_milli');
            $table->string('unit', 30)->default('unit');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('taxable_amount');
            $table->unsignedSmallInteger('tax_rate_bps')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedSmallInteger('withholding_rate_bps')->default(0);
            $table->unsignedBigInteger('withholding_amount')->default(0);
            $table->unsignedBigInteger('total');
            $table->foreignUuid('expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['supplier_bill_id', 'position']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('number', 40);
            $table->date('payment_date');
            $table->date('posting_date');
            $table->unsignedBigInteger('amount');
            $table->string('method', 30);
            $table->foreignUuid('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 100);
            $table->char('idempotency_hash', 64);
            $table->foreignUuid('journal_id')->constrained('journals')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'sequence']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'supplier_id', 'posting_date']);
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supplier_bill_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamps();
            $table->unique(['supplier_payment_id', 'supplier_bill_id']);
            $table->index(['supplier_bill_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_bill_lines');
        Schema::dropIfExists('supplier_bills');
        Schema::dropIfExists('purchase_receipt_lines');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
