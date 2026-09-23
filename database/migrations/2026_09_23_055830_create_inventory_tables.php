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
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('sku', 80);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 30)->default('inventory');
            $table->boolean('track_inventory')->default(true);
            $table->string('unit', 30)->default('unit');
            $table->string('sales_unit', 30)->nullable();
            $table->string('purchase_unit', 30)->nullable();
            $table->string('category')->nullable();
            $table->string('barcode', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('sales_price')->default(0);
            $table->unsignedBigInteger('default_purchase_cost')->default(0);
            $table->unsignedBigInteger('reorder_level_milli')->default(0);
            $table->unsignedBigInteger('reorder_quantity_milli')->default(0);
            $table->foreignUuid('inventory_asset_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignUuid('cogs_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignUuid('sales_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignUuid('inventory_adjustment_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'sku']);
            $table->unique(['company_id', 'barcode']);
            $table->index(['company_id', 'type', 'is_active']);
            $table->index(['company_id', 'category']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->text('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'is_default']);
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('number', 40);
            $table->string('type', 40);
            $table->date('transaction_date');
            $table->foreignUuid('source_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('destination_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->string('source_type', 80)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('reference')->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->string('idempotency_key', 120);
            $table->char('idempotency_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'sequence']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'transaction_date', 'type']);
            $table->index(['company_id', 'source_type', 'source_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('inventory_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->date('movement_date');
            $table->unsignedBigInteger('quantity_in_milli')->default(0);
            $table->unsignedBigInteger('quantity_out_milli')->default(0);
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->unsignedBigInteger('movement_value')->default(0);
            $table->bigInteger('value_delta');
            $table->string('source_type', 80)->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('source_line_id')->nullable();
            $table->foreignUuid('original_movement_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'item_id', 'warehouse_id', 'movement_date'], 'inventory_movement_ledger_index');
            $table->index(['company_id', 'source_type', 'source_id', 'source_line_id'], 'inventory_movement_source_index');
        });

        Schema::create('inventory_layers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('source_movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $table->unsignedBigInteger('original_quantity_milli');
            $table->unsignedBigInteger('remaining_quantity_milli');
            $table->unsignedBigInteger('unit_cost');
            $table->unsignedBigInteger('original_value');
            $table->unsignedBigInteger('remaining_value');
            $table->date('received_date');
            $table->timestamps();
            $table->index(['company_id', 'item_id', 'warehouse_id', 'received_date'], 'inventory_fifo_index');
        });

        Schema::create('inventory_consumptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outbound_movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignUuid('inventory_layer_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quantity_milli');
            $table->unsignedBigInteger('unit_cost');
            $table->unsignedBigInteger('value');
            $table->timestamps();
            $table->unique(['outbound_movement_id', 'inventory_layer_id'], 'inventory_consumption_unique');
            $table->index(['company_id', 'inventory_layer_id']);
        });

        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->foreignUuid('warehouse_id')->nullable()->after('supplier_id')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
        Schema::dropIfExists('inventory_consumptions');
        Schema::dropIfExists('inventory_layers');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('inventory_items');
    }
};
