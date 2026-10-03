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
        Schema::table('employees', function (Blueprint $table) {
            $table->text('address')->nullable();
            $table->unsignedInteger('self_profile_version')->default(1);
        });

        Schema::create('employee_emergency_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->text('relationship');
            $table->text('phone');
            $table->unsignedInteger('version')->default(1);
            $table->char('create_request_key_hash', 64)->nullable();
            $table->char('create_payload_hash', 64)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['company_id', 'employee_id', 'deleted_at'], 'emergency_contacts_employee_index');
            $table->unique(['company_id', 'employee_id', 'create_request_key_hash'], 'emergency_contacts_create_key');
        });

        $now = now();
        DB::table('permissions')->insertOrIgnore([
            'name' => 'employee.profile.edit', 'description' => 'Edit own address and emergency contacts.',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_emergency_contacts');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['address', 'self_profile_version']);
        });
    }
};
