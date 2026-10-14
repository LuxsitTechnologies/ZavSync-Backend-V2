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
        Schema::create('ai_usage_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->string('operation', 40);
            $table->string('status', 24);
            $table->unsignedInteger('reserved_tokens');
            $table->unsignedInteger('actual_tokens')->nullable();
            $table->string('usage_hash', 64)->nullable();
            $table->foreignUuid('usage_record_id')->nullable()->unique()->constrained('ai_usage_records')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['company_id', 'period_start', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage_reservations');
    }
};
