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
        Schema::create('intelligence_briefings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('period', 20);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 30);
            $table->longText('structured_data');
            $table->longText('narrative')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('fingerprint', 64);
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->unique(['company_id', 'fingerprint']);
            $table->index(['company_id', 'period', 'generated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intelligence_briefings');
    }
};
