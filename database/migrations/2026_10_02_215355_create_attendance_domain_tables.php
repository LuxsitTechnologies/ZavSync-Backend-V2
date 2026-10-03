<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('employee_id');
            $table->foreignUuid('active_employee_id')->nullable();
            $table->date('work_date');
            $table->string('timezone', 64);
            $table->string('state', 24);
            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            $table->unsignedInteger('completed_break_seconds')->default(0);
            $table->unsignedInteger('worked_seconds')->nullable();
            $table->string('provenance', 24)->default('SELF_CLOCK');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'attendance_session_company_identity');
            $table->unique(['company_id', 'active_employee_id'], 'attendance_one_active_employee');
            $table->index(['company_id', 'employee_id', 'work_date', 'id'], 'attendance_employee_history');
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });

        Schema::create('attendance_breaks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('attendance_session_id');
            $table->foreignUuid('active_session_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'attendance_session_id'], 'attendance_break_session_fk')->references(['company_id', 'id'])->on('attendance_sessions')->restrictOnDelete();
            $table->unique(['company_id', 'active_session_id'], 'attendance_one_open_break');
            $table->index(['attendance_session_id', 'started_at']);
        });

        Schema::create('attendance_idempotencies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('employee_id');
            $table->char('key_hash', 64);
            $table->char('payload_hash', 64);
            $table->string('action', 32);
            $table->json('response_snapshot');
            $table->timestamps();
            $table->unique(['company_id', 'employee_id', 'key_hash'], 'attendance_idempotency_identity');
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });

        Schema::create('attendance_correction_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('employee_id');
            $table->foreignUuid('attendance_session_id');
            $table->string('kind', 24);
            $table->string('status', 24);
            $table->char('request_key_hash', 64)->nullable();
            $table->json('original_snapshot');
            $table->json('proposed_snapshot');
            $table->text('reason');
            $table->text('decision_reason')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'attendance_correction_company_identity');
            $table->unique(['company_id', 'employee_id', 'request_key_hash'], 'attendance_correction_request_key');
            $table->foreign(['company_id', 'attendance_session_id'], 'attendance_correction_session_fk')->references(['company_id', 'id'])->on('attendance_sessions')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->index(['company_id', 'status', 'created_at'], 'attendance_correction_queue');
            $table->index(['company_id', 'employee_id', 'created_at'], 'attendance_correction_self');
        });

        Schema::create('attendance_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('attendance_session_id');
            $table->foreignUuid('attendance_correction_request_id')->unique('attendance_revision_one_decision');
            $table->unsignedInteger('revision_number');
            $table->json('before_snapshot');
            $table->json('effective_snapshot');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->foreign(['company_id', 'attendance_session_id'], 'attendance_revision_session_fk')->references(['company_id', 'id'])->on('attendance_sessions')->restrictOnDelete();
            $table->foreign(['company_id', 'attendance_correction_request_id'], 'attendance_revision_correction_fk')->references(['company_id', 'id'])->on('attendance_correction_requests')->restrictOnDelete();
            $table->unique(['attendance_session_id', 'revision_number'], 'attendance_session_revision_number');
        });

        $time = now();
        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $name): array => ['name' => $name, 'description' => 'Attendance access.', 'created_at' => $time, 'updated_at' => $time],
            ['employee.attendance.view', 'employee.attendance.clock', 'employee.attendance.correction.request', 'attendance.view', 'attendance.manage', 'attendance.corrections.manage'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_revisions');
        Schema::dropIfExists('attendance_correction_requests');
        Schema::dropIfExists('attendance_idempotencies');
        Schema::dropIfExists('attendance_breaks');
        Schema::dropIfExists('attendance_sessions');
    }
};
