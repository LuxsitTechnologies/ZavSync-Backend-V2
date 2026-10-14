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
        Schema::create('billing_component_quantity_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('component_subscription_id')
                ->constrained('billing_component_subscriptions', indexName: 'billing_component_quantity_component_fk')
                ->restrictOnDelete();
            $key = $table->string('idempotency_key', 100);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $key->charset('utf8mb4')->collation('utf8mb4_nopad_bin');
            }
            $table->unsignedInteger('previous_quantity');
            $table->unsignedInteger('target_quantity');
            $table->string('status', 30);
            $table->unsignedBigInteger('claim_generation')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key'], 'billing_component_quantity_company_key_unique');
            $table->index(['company_id', 'component_subscription_id', 'status'], 'billing_component_quantity_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_component_quantity_changes');
    }
};
