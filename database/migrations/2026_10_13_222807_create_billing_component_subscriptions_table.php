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
        Schema::create('billing_component_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('catalogue_price_id')->constrained('billing_catalogue_prices')->restrictOnDelete();
            $table->foreignUuid('checkout_attempt_id')->unique()->constrained('billing_checkout_attempts')->restrictOnDelete();
            $table->string('kind', 30);
            $table->string('code', 60);
            $table->char('currency', 3);
            $table->string('billing_interval', 20);
            $table->unsignedInteger('quantity');
            $table->string('status', 30);
            $providerSubscriptionId = $table->string('provider_subscription_id', 255)->unique();
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $providerSubscriptionId->charset('utf8mb4')->collation('utf8mb4_nopad_bin');
            }
            $table->timestamp('paid_through_at');
            $table->timestamp('cancel_at_period_end_at')->nullable();
            $table->unsignedInteger('scheduled_quantity')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'subscription_id', 'kind', 'code'], 'billing_component_identity_index');
            $table->index(['company_id', 'status', 'paid_through_at'], 'billing_component_paid_index');
        });

        Schema::table('billing_subscription_items', function (Blueprint $table): void {
            $table->foreignUuid('component_subscription_id')->nullable()
                ->after('subscription_id')->constrained('billing_component_subscriptions')->restrictOnDelete();
        });

        Schema::table('billing_documents', function (Blueprint $table): void {
            $table->foreignUuid('component_subscription_id')->nullable()
                ->after('subscription_id')->constrained('billing_component_subscriptions')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('component_subscription_id');
        });
        Schema::table('billing_subscription_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('component_subscription_id');
        });
        Schema::dropIfExists('billing_component_subscriptions');
    }
};
