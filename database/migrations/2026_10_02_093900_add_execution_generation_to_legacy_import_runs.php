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
        Schema::table('legacy_import_runs', function (Blueprint $table) {
            $table->bigInteger('execution_generation')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('legacy_import_runs', function (Blueprint $table) {
            $table->dropColumn('execution_generation');
        });
    }
};
