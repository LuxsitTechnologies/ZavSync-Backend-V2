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
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('knowledge_source_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('source_version');
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            $table->string('content_hash', 64);
            $table->json('locator')->nullable();
            $table->longText('embedding')->nullable();
            $table->timestamps();
            $table->unique(['knowledge_source_id', 'source_version', 'chunk_index'], 'knowledge_chunks_source_version_index_unique');
            $table->index(['company_id', 'knowledge_source_id', 'source_version'], 'knowledge_chunks_retrieval_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
