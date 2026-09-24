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
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('job_title')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('mobile', 40)->nullable();
            $table->string('website')->nullable();
            $table->string('source', 80)->nullable();
            $table->string('status', 20)->default('NEW');
            $table->unsignedBigInteger('estimated_value')->default(0);
            $table->char('currency', 3)->default('PKR');
            $table->date('expected_timeframe')->nullable();
            $table->string('interest')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('score')->default(0);
            $table->text('qualification_notes')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->string('conversion_idempotency_key', 100)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'owner_id']);
            $table->index(['company_id', 'score']);
            $table->index(['company_id', 'email']);
            $table->index(['company_id', 'phone']);
            $table->index(['company_id', 'created_at']);
            $table->unique(['company_id', 'conversion_idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_leads');
    }
};
