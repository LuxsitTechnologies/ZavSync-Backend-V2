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
        Schema::create('crm_deals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('account_id')->constrained('crm_accounts')->restrictOnDelete();
            $table->foreignUuid('primary_contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->foreignUuid('lead_origin_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->foreignUuid('pipeline_id')->constrained('crm_pipelines')->restrictOnDelete();
            $table->foreignUuid('pipeline_stage_id')->constrained('crm_pipeline_stages')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_handoff_key', 100)->nullable();
            $table->string('title');
            $table->unsignedBigInteger('amount')->default(0);
            $table->char('currency', 3)->default('PKR');
            $table->unsignedSmallInteger('probability_bps')->default(0);
            $table->date('expected_close_date')->nullable();
            $table->date('actual_close_date')->nullable();
            $table->string('status', 20)->default('OPEN');
            $table->string('source', 80)->nullable();
            $table->text('description')->nullable();
            $table->text('loss_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->unique(['company_id', 'customer_handoff_key']);
            $table->index(['company_id', 'pipeline_id', 'pipeline_stage_id']);
            $table->index(['company_id', 'owner_id']);
            $table->index(['company_id', 'account_id']);
            $table->index(['company_id', 'expected_close_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_deals');
    }
};
