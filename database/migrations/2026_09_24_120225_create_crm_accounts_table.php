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
        Schema::create('crm_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_handoff_key', 100)->nullable();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('website')->nullable();
            $table->string('ntn', 7)->nullable();
            $table->string('cnic', 13)->nullable();
            $table->string('registration_number', 80)->nullable();
            $table->string('industry')->nullable();
            $table->string('account_type', 40)->default('BUSINESS');
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->char('country', 2)->default('PK');
            $table->string('postal_code', 20)->nullable();
            $table->string('source', 80)->nullable();
            $table->string('status', 20)->default('PROSPECT');
            $table->text('notes')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'customer_id']);
            $table->unique(['company_id', 'customer_handoff_key']);
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'status', 'is_archived']);
            $table->index(['company_id', 'owner_id']);
            $table->index(['company_id', 'ntn']);
            $table->index(['company_id', 'email']);
            $table->index(['company_id', 'phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_accounts');
    }
};
