<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->string('result', 32)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('security_events')->whereRaw('LENGTH(result) > 20')->exists()) {
            throw new RuntimeException('Security event results longer than 20 characters cannot be safely narrowed.');
        }

        Schema::table('security_events', function (Blueprint $table) {
            $table->string('result', 20)->change();
        });
    }
};
