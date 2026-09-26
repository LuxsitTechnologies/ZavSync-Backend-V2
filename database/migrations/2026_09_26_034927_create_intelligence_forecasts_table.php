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
        Schema::create('intelligence_forecasts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 100);
            $table->string('source_module', 40);
            $table->string('method', 60);
            $table->unsignedSmallInteger('horizon_days');
            $table->string('status', 30);
            $table->json('source_data');
            $table->json('assumptions');
            $table->json('projection_points');
            $table->unsignedSmallInteger('confidence_bps')->nullable();
            $table->text('limitations')->nullable();
            $table->timestamp('generated_at');
            $table->string('fingerprint', 64);
            $table->timestamps();
            $table->unique(['company_id', 'fingerprint']);
            $table->index(['company_id', 'metric', 'generated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intelligence_forecasts');
    }
};
