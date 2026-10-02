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
        Schema::create('ai_provider_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 40);
            $table->date('period_start');
            $table->date('period_end');
            $table->bigInteger('internal_cost_minor');
            $table->bigInteger('provider_cost_minor')->nullable();
            $table->bigInteger('difference_minor')->nullable();
            $table->string('status', 30);
            $table->string('provider_reference')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'provider', 'period_start', 'period_end'], 'apr_company_provider_period_uq');
            $table->index(['company_id', 'status', 'period_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_provider_reconciliations');
    }
};
