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
            $table->text('outreach_physical_address')->nullable();
            $table->text('outreach_footer')->nullable();
            $table->boolean('outreach_open_tracking_enabled')->default(true);
            $table->boolean('outreach_click_tracking_enabled')->default(true);
            $table->boolean('outreach_unsubscribe_required')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'outreach_physical_address', 'outreach_footer', 'outreach_open_tracking_enabled',
                'outreach_click_tracking_enabled', 'outreach_unsubscribe_required',
            ]);
        });
    }
};
