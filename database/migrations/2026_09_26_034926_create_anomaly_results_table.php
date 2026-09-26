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
        Schema::create('anomaly_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('source_module', 40);
            $table->string('metric', 100);
            $table->string('method', 40);
            $table->bigInteger('observed_value');
            $table->bigInteger('expected_value');
            $table->bigInteger('deviation_value');
            $table->integer('deviation_bps')->nullable();
            $table->unsignedInteger('threshold_bps');
            $table->unsignedSmallInteger('sample_size');
            $table->date('window_start');
            $table->date('window_end');
            $table->timestamp('evaluated_at');
            $table->string('status', 20)->default('ACTIVE');
            $table->string('fingerprint', 64);
            $table->json('source_metrics');
            $table->text('explanation');
            $table->timestamps();
            $table->unique(['company_id', 'fingerprint']);
            $table->index(['company_id', 'source_module', 'evaluated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anomaly_results');
    }
};
