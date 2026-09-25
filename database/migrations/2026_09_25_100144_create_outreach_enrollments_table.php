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
        Schema::create('outreach_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sequence_id')->constrained('outreach_sequences')->cascadeOnDelete();
            $table->string('recipient_type', 20);
            $table->uuid('recipient_id');
            $table->string('recipient_email');
            $table->string('recipient_name');
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedSmallInteger('current_step_position')->default(1);
            $table->timestamp('next_action_at')->nullable();
            $table->string('idempotency_key');
            $table->string('idempotency_hash', 64);
            $table->timestamp('enrolled_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
            $table->unique(['sequence_id', 'recipient_type', 'recipient_id'], 'outreach_enrollment_recipient_unique');
            $table->index(['company_id', 'status', 'next_action_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outreach_enrollments');
    }
};
