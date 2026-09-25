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
        Schema::create('outreach_sequence_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sequence_id')->constrained('outreach_sequences')->cascadeOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('type', 10);
            $table->string('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->unsignedInteger('wait_minutes')->default(0);
            $table->timestamps();
            $table->unique(['sequence_id', 'position']);
            $table->index(['company_id', 'sequence_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outreach_sequence_steps');
    }
};
