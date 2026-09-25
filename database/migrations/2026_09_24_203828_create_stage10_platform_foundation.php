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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('password');
        });

        Schema::table('company_users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('is_active');
            $table->foreignId('suspended_by')->nullable()->after('suspended_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('is_system');
        });

        Schema::create('company_user_role', function (Blueprint $table) {
            $table->foreignId('company_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['company_user_id', 'role_id']);
        });

        Schema::create('company_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->string('status', 20)->default('PENDING');
            $table->json('role_ids');
            $table->foreignId('invited_by')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'expires_at']);
            $table->index(['company_id', 'email', 'status']);
        });

        Schema::create('company_settings', function (Blueprint $table) {
            $table->foreignUuid('company_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('tax_identifier')->nullable();
            $table->string('cnic')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->char('country_code', 2)->default('PK');
            $table->string('timezone')->default('Asia/Karachi');
            $table->char('base_currency', 3)->default('PKR');
            $table->string('date_format')->default('DD/MM/YYYY');
            $table->string('time_format')->default('24h');
            $table->string('number_format')->default('1,234.56');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(7);
            $table->unsignedSmallInteger('default_payment_terms_days')->default(30);
            $table->uuid('default_warehouse_id')->nullable();
            $table->uuid('default_financial_account_id')->nullable();
            $table->string('invoice_prefix', 20)->default('INV');
            $table->string('purchase_prefix', 20)->default('PO');
            $table->string('logo_path')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('platform_modules', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->char('currency', 3)->default('PKR');
            $table->string('billing_interval', 20)->default('monthly');
            $table->json('usage_limits')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plan_modules', function (Blueprint $table) {
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 40);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->foreign('module_key')->references('key')->on('platform_modules')->cascadeOnDelete();
            $table->primary(['plan_id', 'module_key']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('status', 20)->default('TRIALING');
            $table->string('billing_interval', 20)->default('monthly');
            $table->timestamp('starts_at');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'starts_at']);
        });

        Schema::create('company_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 40);
            $table->boolean('is_enabled');
            $table->json('limits')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign('module_key')->references('key')->on('platform_modules')->cascadeOnDelete();
            $table->unique(['company_id', 'module_key']);
        });

        Schema::create('platform_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 80);
            $table->string('channel', 20)->default('IN_APP');
            $table->string('title');
            $table->text('message');
            $table->string('related_type')->nullable();
            $table->string('related_id')->nullable();
            $table->string('related_url')->nullable();
            $table->string('delivery_state', 20)->default('PENDING');
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'recipient_id', 'read_at', 'created_at'], 'platform_notifications_inbox_index');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80);
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'user_id', 'type']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('documentable_type');
            $table->string('documentable_id');
            $table->string('category', 60)->default('general');
            $table->string('original_filename');
            $table->string('storage_disk', 40)->default('local');
            $table->string('storage_key');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64);
            $table->timestamps();
            $table->index(['company_id', 'documentable_type', 'documentable_id'], 'documents_owner_index');
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 80);
            $table->string('result', 20);
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'type', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('company_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('status', 20)->default('PENDING');
            $table->json('sections');
            $table->string('storage_disk', 40)->default('local');
            $table->string('storage_key')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_exports');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('platform_notifications');
        Schema::dropIfExists('company_entitlements');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_modules');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('platform_modules');
        Schema::dropIfExists('company_settings');
        Schema::dropIfExists('company_invitations');
        Schema::dropIfExists('company_user_role');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });

        Schema::table('company_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropColumn('suspended_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_admin');
        });
    }
};
