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
            $table->json('dashboard_layout')->nullable();
            $table->unsignedTinyInteger('dashboard_layout_columns')->default(4);
            $table->unsignedBigInteger('dashboard_layout_version')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['dashboard_layout', 'dashboard_layout_columns', 'dashboard_layout_version']);
        });
    }
};
