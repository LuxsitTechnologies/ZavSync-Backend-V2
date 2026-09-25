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
        Schema::create('outreach_send_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('message_id')->constrained('outreach_messages')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('idempotency_key')->unique();
            $table->string('status', 20)->default('STARTED');
            $table->string('provider_message_id')->nullable();
            $table->json('provider_response')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'attempt_number']);
            $table->index(['company_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outreach_send_attempts');
    }
};
