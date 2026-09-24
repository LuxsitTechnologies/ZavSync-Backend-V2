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
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->foreignUuid('converted_account_id')->nullable()->after('converted_at')->constrained('crm_accounts')->nullOnDelete();
            $table->foreignUuid('converted_contact_id')->nullable()->after('converted_account_id')->constrained('crm_contacts')->nullOnDelete();
            $table->foreignUuid('converted_deal_id')->nullable()->after('converted_contact_id')->constrained('crm_deals')->nullOnDelete();
            $table->index(['company_id', 'converted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropForeign(['converted_account_id']);
            $table->dropForeign(['converted_contact_id']);
            $table->dropForeign(['converted_deal_id']);
            $table->dropIndex(['company_id', 'converted_at']);
            $table->dropColumn(['converted_account_id', 'converted_contact_id', 'converted_deal_id']);
        });
    }
};
