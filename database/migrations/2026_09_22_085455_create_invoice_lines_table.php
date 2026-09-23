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
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->uuid('item_id')->nullable();
            $table->string('item_name')->nullable();
            $table->text('description');
            $table->unsignedBigInteger('quantity_milli');
            $table->string('unit', 30)->default('unit');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount');
            $table->unsignedBigInteger('taxable_amount');
            $table->unsignedSmallInteger('tax_rate_bps')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedSmallInteger('other_tax_rate_bps')->default(0);
            $table->unsignedBigInteger('other_tax_amount')->default(0);
            $table->unsignedSmallInteger('advance_tax_rate_bps')->default(0);
            $table->unsignedBigInteger('advance_tax_amount')->default(0);
            $table->unsignedSmallInteger('withholding_tax_rate_bps')->default(0);
            $table->unsignedBigInteger('withholding_tax_amount')->default(0);
            $table->unsignedBigInteger('total');
            $table->string('sales_type', 60)->default('Standardized Goods');
            $table->json('tax_metadata')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
