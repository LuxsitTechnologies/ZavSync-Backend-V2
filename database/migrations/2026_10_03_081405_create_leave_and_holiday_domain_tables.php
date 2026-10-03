<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'name']);
        });
        Schema::create('leave_entitlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('employee_id');
            $table->foreignUuid('leave_type_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('allocated_units');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'employee_id', 'leave_type_id', 'year'], 'leave_entitlement_period');
            $table->foreign(['company_id', 'employee_id'], 'leave_entitlement_employee_fk')->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_type_id'], 'leave_entitlement_type_fk')->references(['company_id', 'id'])->on('leave_types')->restrictOnDelete();
        });
        Schema::create('leave_entitlement_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('leave_entitlement_id');
            $table->integer('delta_units');
            $table->text('reason');
            $table->char('request_key_hash', 64);
            $table->char('payload_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->foreign(['company_id', 'leave_entitlement_id'], 'leave_adjustment_entitlement_fk')->references(['company_id', 'id'])->on('leave_entitlements')->restrictOnDelete();
            $table->unique(['company_id', 'leave_entitlement_id', 'request_key_hash'], 'leave_adjustment_idempotency');
        });
        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('employee_id');
            $table->foreignUuid('leave_type_id');
            $table->foreignUuid('leave_entitlement_id')->nullable();
            $table->string('type_name_snapshot', 120);
            $table->boolean('is_paid_snapshot');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('day_portion', 16);
            $table->unsignedInteger('units');
            $table->string('status', 32);
            $table->text('reason');
            $table->char('request_key_hash', 64);
            $table->char('payload_hash', 64);
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'employee_id', 'request_key_hash'], 'leave_request_idempotency');
            $table->index(['company_id', 'employee_id', 'start_date', 'end_date'], 'leave_request_overlap');
            $table->index(['company_id', 'status', 'created_at'], 'leave_request_queue');
            $table->foreign(['company_id', 'employee_id'], 'leave_request_employee_fk')->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_type_id'], 'leave_request_type_fk')->references(['company_id', 'id'])->on('leave_types')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_entitlement_id'], 'leave_request_entitlement_fk')->references(['company_id', 'id'])->on('leave_entitlements')->restrictOnDelete();
        });
        Schema::create('leave_request_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('leave_request_id');
            $table->string('action', 32);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->foreign(['company_id', 'leave_request_id'], 'leave_event_request_fk')->references(['company_id', 'id'])->on('leave_requests')->restrictOnDelete();
            $table->index(['leave_request_id', 'created_at']);
        });
        Schema::create('company_holidays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->date('date');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'date', 'name']);
            $table->index(['company_id', 'date']);
        });
        $time = now();
        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $name): array => ['name' => $name, 'description' => 'Leave and holiday access.', 'created_at' => $time, 'updated_at' => $time],
            ['employee.leave.view', 'employee.leave.request', 'employee.leave.cancel', 'leave.view', 'leave.manage', 'leave.approve', 'holiday.view', 'holiday.manage'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('company_holidays');
        Schema::dropIfExists('leave_request_events');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_entitlement_adjustments');
        Schema::dropIfExists('leave_entitlements');
        Schema::dropIfExists('leave_types');
    }
};
