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
        Schema::create('knowledge_ingestion_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('knowledge_source_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 120);
            $table->unsignedInteger('source_version');
            $table->string('status', 20)->default('PENDING');
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_ingestion_runs');
    }
};
