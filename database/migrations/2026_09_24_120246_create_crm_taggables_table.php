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
        Schema::create('crm_taggables', function (Blueprint $table) {
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('crm_tag_id')->constrained('crm_tags')->cascadeOnDelete();
            $table->uuidMorphs('taggable');
            $table->unique(['crm_tag_id', 'taggable_type', 'taggable_id'], 'crm_taggables_unique');
            $table->index(['company_id', 'taggable_type', 'taggable_id'], 'crm_taggables_company_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_taggables');
    }
};
