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
        Schema::create('journals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('number');
            $table->date('posting_date');
            $table->string('reference')->nullable();
            $table->string('reference_type', 40)->default('manual_journal');
            $table->uuid('source_id')->nullable();
            $table->string('source', 50)->default('manual');
            $table->string('idempotency_key', 100)->nullable();
            $table->char('idempotency_hash', 64)->nullable();
            $table->text('description');
            $table->string('status', 12)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignUuid('reverses_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignUuid('reversed_by_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'sequence']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'posting_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
