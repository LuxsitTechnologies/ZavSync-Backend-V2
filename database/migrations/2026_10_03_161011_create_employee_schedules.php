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
        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->char('create_request_key_hash', 64)->nullable();
            $table->char('create_payload_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'employee_shifts_company_id_uniq');
            $table->unique(['company_id', 'name'], 'employee_shifts_company_name_uniq');
            $table->unique(['company_id', 'created_by', 'create_request_key_hash'], 'employee_shifts_create_key_uniq');
        });
        Schema::create('employee_rotas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('timezone', 64);
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('create_request_key_hash', 64)->nullable();
            $table->char('create_payload_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'employee_rotas_company_id_uniq');
            $table->unique(['company_id', 'created_by', 'create_request_key_hash'], 'employee_rotas_create_key_uniq');
            $table->index(['company_id', 'start_date', 'end_date'], 'employee_rotas_date_idx');
        });
        Schema::create('employee_rota_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_rota_id');
            $table->foreignUuid('employee_shift_id');
            $table->date('shift_date');
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->unsignedSmallInteger('required_coverage')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->char('create_request_key_hash', 64)->nullable();
            $table->char('create_payload_hash', 64)->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'employee_rota_id'], 'rota_slots_rota_company_fk')
                ->references(['company_id', 'id'])->on('employee_rotas')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_shift_id'], 'rota_slots_shift_company_fk')
                ->references(['company_id', 'id'])->on('employee_shifts')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'rota_slots_company_id_uniq');
            $table->unique(['employee_rota_id', 'employee_shift_id', 'shift_date'], 'rota_slots_shift_date_uniq');
            $table->unique(['company_id', 'created_by', 'create_request_key_hash'], 'rota_slots_create_key_uniq');
            $table->index(['company_id', 'shift_date', 'start_time'], 'rota_slots_date_time_idx');
        });
        Schema::create('employee_shift_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_rota_slot_id');
            $table->foreignUuid('employee_id');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->char('create_request_key_hash', 64)->nullable();
            $table->char('create_payload_hash', 64)->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'employee_rota_slot_id'], 'shift_assignments_slot_company_fk')
                ->references(['company_id', 'id'])->on('employee_rota_slots')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'], 'shift_assignments_employee_company_fk')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'shift_assignments_company_id_uniq');
            $table->unique(['employee_rota_slot_id', 'employee_id'], 'shift_assignments_slot_employee_uniq');
            $table->unique(['company_id', 'created_by', 'create_request_key_hash'], 'shift_assignments_create_key_uniq');
            $table->index(['company_id', 'employee_id'], 'shift_assignments_employee_idx');
        });
        Schema::create('employee_shift_swaps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('requester_employee_id');
            $table->foreignUuid('target_employee_id');
            $table->foreignUuid('from_assignment_id');
            $table->foreignUuid('to_assignment_id');
            $table->unsignedInteger('from_assignment_version');
            $table->unsignedInteger('to_assignment_version');
            $table->string('status', 30)->default('PENDING_TARGET');
            $table->unsignedInteger('version')->default(1);
            $table->text('reason')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('target_accepted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('create_request_key_hash', 64)->nullable();
            $table->char('create_payload_hash', 64)->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'requester_employee_id'], 'shift_swaps_requester_company_fk')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'target_employee_id'], 'shift_swaps_target_company_fk')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'from_assignment_id'], 'shift_swaps_from_company_fk')
                ->references(['company_id', 'id'])->on('employee_shift_assignments')->restrictOnDelete();
            $table->foreign(['company_id', 'to_assignment_id'], 'shift_swaps_to_company_fk')
                ->references(['company_id', 'id'])->on('employee_shift_assignments')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'shift_swaps_company_id_uniq');
            $table->unique(['company_id', 'requester_employee_id', 'create_request_key_hash'], 'shift_swaps_create_key_uniq');
            $table->index(['company_id', 'status'], 'shift_swaps_status_idx');
        });
        Schema::create('employee_shift_swap_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_shift_swap_id');
            $table->string('event_type', 30);
            $table->unsignedInteger('swap_version');
            $table->foreignId('actor_id')->constrained('users');
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->foreign(['company_id', 'employee_shift_swap_id'], 'shift_swap_events_company_fk')
                ->references(['company_id', 'id'])->on('employee_shift_swaps')->cascadeOnDelete();
            $table->unique(['employee_shift_swap_id', 'swap_version'], 'shift_swap_events_version_uniq');
        });
        $now = now();
        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $name): array => ['name' => $name, 'description' => 'Employee scheduling access.', 'created_at' => $now, 'updated_at' => $now],
            ['employee.schedule.view', 'employee.schedule.swap.request', 'employee.schedule.swap.respond', 'schedules.view', 'schedules.manage', 'schedules.swaps.decide'],
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_shift_swap_events');
        Schema::dropIfExists('employee_shift_swaps');
        Schema::dropIfExists('employee_shift_assignments');
        Schema::dropIfExists('employee_rota_slots');
        Schema::dropIfExists('employee_rotas');
        Schema::dropIfExists('employee_shifts');
    }
};
