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
            $table->boolean('billing_policy_applies')->default(false);
        });

        Schema::table('company_settings', function (Blueprint $table): void {
            $table->timestamp('billing_country_confirmed_at')->nullable();
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->char('billing_currency', 3)->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('cancel_at_period_end_at')->nullable();
            $table->string('provider_event_id', 255)->nullable();
        });

        Schema::create('billing_catalogue_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 30);
            $table->string('code', 60);
            $table->char('currency', 3);
            $table->string('billing_interval', 20);
            $table->unsignedSmallInteger('version');
            $table->unsignedBigInteger('unit_amount_minor')->nullable();
            $table->boolean('is_purchasable')->default(false);
            $table->string('provider_price_id', 255)->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'code', 'currency', 'billing_interval', 'version'], 'billing_catalogue_identity_unique');
            $table->unique('provider_price_id', 'billing_catalogue_provider_price_unique');
        });

        Schema::create('billing_customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('provider_customer_id', 255)->unique();
            $table->timestamps();
        });

        Schema::create('billing_checkout_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $key = $table->string('idempotency_key', 100);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $key->charset('utf8mb4')->collation('utf8mb4_nopad_bin');
            }
            $table->string('payload_hash', 64);
            $table->string('operation', 40);
            $table->string('status', 30);
            $table->unsignedBigInteger('claim_generation')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->nullable();
            $table->unsignedBigInteger('total_minor');
            $table->json('price_snapshot');
            $table->string('provider_session_id', 255)->nullable()->unique();
            $table->string('provider_subscription_id', 255)->nullable();
            $table->string('provider_customer_id', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key'], 'billing_checkout_company_key_unique');
            $table->index(['company_id', 'status', 'created_at']);
        });

        Schema::create('billing_provider_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 30);
            $event = $table->string('provider_event_id', 255);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $event->charset('utf8mb4')->collation('utf8mb4_nopad_bin');
            }
            $table->foreignUuid('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_type', 100);
            $table->string('provider_object_id', 255)->nullable();
            $table->timestamp('provider_created_at');
            $table->string('processing_state', 30);
            $table->unsignedBigInteger('claim_generation')->default(0);
            $table->string('failure_code', 100)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_event_id'], 'billing_event_identity_unique');
        });

        Schema::create('billing_subscription_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('catalogue_price_id')->nullable()->constrained('billing_catalogue_prices')->nullOnDelete();
            $table->string('kind', 30);
            $table->string('code', 60);
            $table->char('currency', 3);
            $table->string('billing_interval', 20);
            $table->unsignedBigInteger('unit_amount_minor');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('total_minor');
            $table->timestamp('effective_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'subscription_id']);
        });

        Schema::create('billing_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('provider_document_id', 255)->unique();
            $table->string('document_type', 30);
            $table->string('provider_status', 30);
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->nullable();
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('paid_minor')->nullable();
            $table->json('line_snapshot');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'issued_at']);
        });

        Schema::create('billing_usage_meters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('meter_key', 60);
            $table->date('period_start');
            $table->date('period_end');
            $key = $table->string('idempotency_key', 100);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $key->charset('utf8mb4')->collation('utf8mb4_nopad_bin');
            }
            $table->unsignedBigInteger('quantity');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->unique(['company_id', 'meter_key', 'idempotency_key'], 'billing_meter_key_unique');
            $table->index(['company_id', 'meter_key', 'period_start', 'period_end'], 'billing_meter_period_index');
        });

        Schema::create('billing_notices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $table->string('notice_type', 30);
            $table->date('notice_date');
            $table->timestamp('created_at');
            $table->unique(['subscription_id', 'notice_type', 'notice_date'], 'billing_notice_identity_unique');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_notices');
        Schema::dropIfExists('billing_usage_meters');
        Schema::dropIfExists('billing_documents');
        Schema::dropIfExists('billing_subscription_items');
        Schema::dropIfExists('billing_provider_events');
        Schema::dropIfExists('billing_checkout_attempts');
        Schema::dropIfExists('billing_customers');
        Schema::dropIfExists('billing_catalogue_prices');
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['billing_currency', 'grace_ends_at', 'cancel_at_period_end_at', 'provider_event_id']);
        });
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn('billing_country_confirmed_at');
        });
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('billing_policy_applies');
        });
    }
};
