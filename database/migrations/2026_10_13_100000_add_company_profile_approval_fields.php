<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('approval_status', 20)->default('APPROVED');
            $table->string('company_size', 100)->nullable();
            $table->string('province_code', 100)->nullable();
            $table->unsignedBigInteger('profile_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $key = $table->string('creation_idempotency_key', 100)->nullable();
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $key->charset('utf8mb4')->collation('utf8mb4_nopad_bin');
            }
            $table->string('creation_idempotency_hash', 64)->nullable();
            $table->unique(['created_by', 'creation_idempotency_key'], 'company_creation_actor_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['approval_status', 'company_size', 'province_code', 'profile_version']);
            $table->dropUnique('company_creation_actor_key_unique');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['creation_idempotency_key', 'creation_idempotency_hash']);
        });
    }
};
