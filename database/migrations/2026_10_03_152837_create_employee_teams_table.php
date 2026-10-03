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
            $table->foreignUuid('manager_employee_id')->nullable();
            $table->unsignedInteger('manager_version')->default(1);
            $table->foreign(['company_id', 'manager_employee_id'], 'employees_manager_company_fk')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });
        Schema::create('employee_teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->foreignUuid('lead_employee_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->char('create_request_key_hash', 64)->nullable();
            $table->char('create_payload_hash', 64)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'employee_teams_company_id_identity_uniq');
            $table->unique(['company_id', 'name'], 'employee_teams_company_name_uniq');
            $table->unique(['company_id', 'created_by', 'create_request_key_hash'], 'employee_teams_create_key');
            $table->foreign(['company_id', 'lead_employee_id'], 'employee_teams_lead_company_fk')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });
        Schema::create('employee_team_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_team_id');
            $table->foreignUuid('employee_id');
            $table->boolean('is_active')->default(true);
            $table->timestamp('left_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_team_id', 'employee_id'], 'team_memberships_team_employee_uniq');
            $table->index(['company_id', 'employee_id', 'is_active'], 'team_memberships_employee_lookup');
            $table->foreign(['company_id', 'employee_team_id'], 'team_memberships_team_company_fk')
                ->references(['company_id', 'id'])->on('employee_teams')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'], 'team_memberships_employee_company_fk')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });

        $now = now();
        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $name): array => ['name' => $name, 'description' => 'Employee team and directory access.', 'created_at' => $now, 'updated_at' => $now],
            ['employee.directory.view', 'employee.teams.view', 'teams.view', 'teams.manage'],
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_team_memberships');
        Schema::dropIfExists('employee_teams');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite'
                ? ['company_id', 'manager_employee_id'] : 'employees_manager_company_fk');
            $table->dropColumn(['manager_employee_id', 'manager_version']);
        });
    }
};
