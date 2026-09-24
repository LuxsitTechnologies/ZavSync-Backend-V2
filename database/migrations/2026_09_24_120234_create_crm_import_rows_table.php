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
        Schema::create('crm_import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('crm_import_id')->constrained('crm_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('source_data');
            $table->json('mapped_data');
            $table->string('state', 30);
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->nullableUuidMorphs('created_record');
            $table->timestamps();
            $table->unique(['crm_import_id', 'row_number']);
            $table->index(['company_id', 'state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_import_rows');
    }
};
