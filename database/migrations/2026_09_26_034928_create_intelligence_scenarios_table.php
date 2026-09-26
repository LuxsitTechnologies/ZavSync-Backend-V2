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
        Schema::create('intelligence_scenarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('name');
            $table->string('scenario_type', 60);
            $table->string('status', 20)->default('COMPLETED');
            $table->longText('assumptions');
            $table->longText('baseline');
            $table->longText('scenario');
            $table->longText('delta');
            $table->string('idempotency_key', 120);
            $table->string('idempotency_hash', 64);
            $table->timestamp('calculated_at');
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'created_by', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intelligence_scenarios');
    }
};
