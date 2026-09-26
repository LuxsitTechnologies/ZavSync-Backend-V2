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
        Schema::create('ai_message_citations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ai_message_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('knowledge_source_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('knowledge_chunk_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('ordinal');
            $table->text('excerpt');
            $table->json('locator')->nullable();
            $table->timestamps();
            $table->unique(['ai_message_id', 'ordinal']);
            $table->index(['company_id', 'knowledge_source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_message_citations');
    }
};
