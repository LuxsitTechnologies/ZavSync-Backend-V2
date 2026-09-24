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
        Schema::create('crm_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('entity_type', 20);
            $table->string('original_filename');
            $table->string('status', 20)->default('PREVIEWED');
            $table->json('mapping');
            $table->json('summary');
            $table->string('file_hash', 64);
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'file_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_imports');
    }
};
