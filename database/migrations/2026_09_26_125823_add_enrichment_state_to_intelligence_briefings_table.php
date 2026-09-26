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
        Schema::table('intelligence_briefings', function (Blueprint $table) {
            $table->string('enrichment_error_code', 80)->nullable()->after('model');
            $table->timestamp('enrichment_attempted_at')->nullable()->after('enrichment_error_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intelligence_briefings', function (Blueprint $table) {
            $table->dropColumn(['enrichment_error_code', 'enrichment_attempted_at']);
        });
    }
};
