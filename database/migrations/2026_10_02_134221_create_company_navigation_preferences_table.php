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
        Schema::create('company_navigation_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $itemKey = $table->string('item_key', 80);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $itemKey->charset('utf8mb4')->collation('utf8mb4_nopad_bin');
            }
            $table->boolean('is_visible');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'item_key'], 'company_nav_company_item_uniq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_navigation_preferences');
    }
};
