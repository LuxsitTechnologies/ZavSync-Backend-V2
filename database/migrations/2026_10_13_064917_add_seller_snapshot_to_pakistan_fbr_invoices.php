<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pakistan_fbr_invoices', function (Blueprint $table) {
            $table->json('seller_snapshot')->nullable()->after('buyer_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('pakistan_fbr_invoices', function (Blueprint $table) {
            $table->dropColumn('seller_snapshot');
        });
    }
};
