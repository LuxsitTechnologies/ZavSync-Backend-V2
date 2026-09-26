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
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('calendar_provider_connection_id')->constrained()->cascadeOnDelete();
            $table->string('external_event_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 30);
            $table->text('attendees')->nullable();
            $table->text('sync_metadata')->nullable();
            $table->string('etag')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['calendar_provider_connection_id', 'external_event_id']);
            $table->index(['company_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
