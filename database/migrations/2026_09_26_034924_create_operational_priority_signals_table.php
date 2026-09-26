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
        Schema::create('operational_priority_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('source_module', 40);
            $table->string('source_type', 80);
            $table->string('source_id', 120)->nullable();
            $table->string('title');
            $table->text('description');
            $table->string('severity', 20);
            $table->unsignedSmallInteger('priority_score');
            $table->unsignedSmallInteger('confidence_bps')->nullable();
            $table->string('status', 20)->default('OPEN');
            $table->string('fingerprint', 64);
            $table->json('supporting_metrics');
            $table->json('score_breakdown');
            $table->json('explanation_metadata')->nullable();
            $table->string('related_url')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('detected_at');
            $table->timestamp('effective_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('last_seen_at');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dismissed_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'fingerprint']);
            $table->index(['company_id', 'status', 'priority_score']);
            $table->index(['company_id', 'source_module', 'effective_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operational_priority_signals');
    }
};
