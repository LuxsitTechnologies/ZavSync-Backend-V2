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
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('portal_settings_version')->default(1);
            $table->text('fbr_document_footer')->nullable();
            $table->text('fbr_document_declaration')->nullable();
            $table->text('fbr_default_notes')->nullable();
            $table->string('fbr_number_prefix_display', 20)->nullable();
            $table->boolean('fbr_show_seller_email')->default(true);
            $table->boolean('fbr_show_seller_phone')->default(true);
            $table->boolean('fbr_show_seller_address')->default(true);
        });

        Schema::table('pakistan_fbr_invoices', function (Blueprint $table): void {
            $table->json('print_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pakistan_fbr_invoices', function (Blueprint $table): void {
            $table->dropColumn('print_snapshot');
        });
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'portal_settings_version', 'fbr_document_footer', 'fbr_document_declaration',
                'fbr_default_notes', 'fbr_number_prefix_display', 'fbr_show_seller_email',
                'fbr_show_seller_phone', 'fbr_show_seller_address',
            ]);
        });
    }
};
