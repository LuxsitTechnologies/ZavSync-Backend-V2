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
        Schema::create('billing_plan_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_catalogue_price_id')->constrained('billing_catalogue_prices')->restrictOnDelete();
            $table->foreignId('target_catalogue_price_id')->constrained('billing_catalogue_prices')->restrictOnDelete();
            $key = $table->string('idempotency_key', 100);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $key->charset('utf8mb4')->collation('utf8mb4_nopad_bin');
            }
            $table->string('payload_hash', 64);
            $table->string('operation', 20);
            $table->string('status', 30);
            $table->unsignedBigInteger('claim_generation')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->string('source_plan_code', 40);
            $table->string('target_plan_code', 40);
            $table->string('target_billing_interval', 20);
            $table->char('currency', 3);
            $table->unsignedBigInteger('target_amount_minor');
            $table->timestamp('previous_paid_through_at');
            $table->timestamp('effective_at')->nullable();
            $table->string('provider_invoice_id', 255)->nullable();
            $table->string('provider_schedule_id', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key'], 'billing_plan_change_company_key_unique');
            $table->index(['company_id', 'subscription_id', 'status'], 'billing_plan_change_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_plan_changes');
    }
};
