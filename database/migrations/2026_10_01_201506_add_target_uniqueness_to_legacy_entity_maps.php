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
        Schema::table('legacy_entity_maps', function (Blueprint $table) {
            $table->unique(['source_system', 'source_entity_type', 'target_id'], 'legacy_entity_maps_target_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('legacy_entity_maps', function (Blueprint $table) {
            $table->dropUnique('legacy_entity_maps_target_unique');
        });
    }
};
