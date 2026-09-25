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
        Schema::create('outreach_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('enrollment_id')->constrained('outreach_enrollments')->cascadeOnDelete();
            $table->foreignUuid('sequence_id')->constrained('outreach_sequences')->restrictOnDelete();
            $table->foreignUuid('sequence_step_id')->constrained('outreach_sequence_steps')->restrictOnDelete();
            $table->foreignUuid('sending_identity_id')->constrained('email_sending_identities')->restrictOnDelete();
            $table->foreignUuid('provider_connection_id')->constrained('email_provider_connections')->restrictOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->string('stable_message_id')->unique();
            $table->string('provider_message_id')->nullable();
            $table->string('recipient_type', 20);
            $table->uuid('recipient_id');
            $table->string('to_email');
            $table->string('to_name');
            $table->string('from_email');
            $table->string('from_name');
            $table->string('reply_to_email')->nullable();
            $table->string('subject');
            $table->longText('body_html')->nullable();
            $table->longText('body_text');
            $table->string('state', 20)->default('SCHEDULED');
            $table->timestamp('scheduled_at');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->string('unsubscribe_token_hash', 64);
            $table->string('tracking_token_hash', 64);
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->unique(['sequence_step_id', 'enrollment_id']);
            $table->index(['company_id', 'state', 'scheduled_at']);
            $table->index(['company_id', 'provider_message_id']);
            $table->index(['company_id', 'to_email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outreach_messages');
    }
};
