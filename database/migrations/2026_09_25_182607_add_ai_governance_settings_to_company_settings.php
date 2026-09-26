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
        Schema::table('company_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('ai_conversation_retention_days')->default(365);
            $table->unsignedSmallInteger('ai_usage_retention_days')->default(730);
            $table->boolean('ai_allow_external_provider')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['ai_conversation_retention_days', 'ai_usage_retention_days', 'ai_allow_external_provider']);
        });
    }
};
