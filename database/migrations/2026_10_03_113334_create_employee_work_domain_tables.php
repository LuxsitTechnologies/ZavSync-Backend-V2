<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('assigned_employee_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 12);
            $table->date('due_date');
            $table->string('status', 24)->default('ASSIGNED');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->char('request_key_hash', 64);
            $table->char('payload_hash', 64);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'request_key_hash'], 'employee_tasks_key_uniq');
            $table->foreign(['company_id', 'assigned_employee_id'], 'employee_tasks_employee_fk')->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->index(['company_id', 'assigned_employee_id', 'status', 'due_date'], 'employee_tasks_self_idx');
            $table->index(['company_id', 'status', 'due_date'], 'employee_tasks_admin_idx');
        });
        Schema::create('employee_task_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('employee_task_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignUuid('from_employee_id')->nullable();
            $table->foreignUuid('to_employee_id')->nullable();
            $table->timestamp('created_at');
            $table->foreign(['company_id', 'employee_task_id'], 'employee_task_event_fk')->references(['company_id', 'id'])->on('employee_tasks')->restrictOnDelete();
            $table->index(['employee_task_id', 'created_at']);
        });
        Schema::create('employee_task_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('employee_task_id');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->char('request_key_hash', 64);
            $table->char('payload_hash', 64);
            $table->timestamp('created_at');
            $table->foreign(['company_id', 'employee_task_id'], 'employee_task_comment_fk')->references(['company_id', 'id'])->on('employee_tasks')->restrictOnDelete();
            $table->unique(['company_id', 'employee_task_id', 'request_key_hash'], 'employee_task_comment_key');
            $table->index(['employee_task_id', 'created_at']);
        });
        Schema::create('employee_tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('employee_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('subject');
            $table->text('description');
            $table->string('category', 120)->nullable();
            $table->string('priority', 12);
            $table->string('status', 24)->default('OPEN');
            $table->unsignedInteger('version')->default(1);
            $table->char('request_key_hash', 64);
            $table->char('payload_hash', 64);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'employee_id', 'request_key_hash'], 'employee_ticket_key');
            $table->foreign(['company_id', 'employee_id'], 'employee_ticket_employee_fk')->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->index(['company_id', 'employee_id', 'status', 'created_at'], 'employee_tickets_self_idx');
            $table->index(['company_id', 'status', 'created_at'], 'employee_tickets_admin_idx');
        });
        Schema::create('employee_ticket_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('employee_ticket_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->timestamp('created_at');
            $table->foreign(['company_id', 'employee_ticket_id'], 'employee_ticket_event_fk')->references(['company_id', 'id'])->on('employee_tickets')->restrictOnDelete();
            $table->index(['employee_ticket_id', 'created_at']);
        });
        Schema::create('employee_ticket_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->foreignUuid('employee_ticket_id');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->char('request_key_hash', 64);
            $table->char('payload_hash', 64);
            $table->timestamp('created_at');
            $table->foreign(['company_id', 'employee_ticket_id'], 'employee_ticket_comment_fk')->references(['company_id', 'id'])->on('employee_tickets')->restrictOnDelete();
            $table->unique(['company_id', 'employee_ticket_id', 'request_key_hash'], 'employee_ticket_comment_key');
            $table->index(['employee_ticket_id', 'created_at']);
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->char('work_request_key_hash', 64)->nullable();
            $table->char('work_payload_hash', 64)->nullable();
            $table->unique(['company_id', 'work_request_key_hash'], 'documents_work_request_key');
        });
        $now = now();
        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $name): array => ['name' => $name, 'description' => 'Employee work access.', 'created_at' => $now, 'updated_at' => $now],
            ['employee.tasks.view', 'employee.tasks.update', 'employee.tasks.comment', 'employee.tickets.view', 'employee.tickets.create', 'employee.tickets.comment', 'tasks.view', 'tasks.manage', 'tasks.assign', 'tickets.view', 'tickets.manage'],
        ));
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropUnique('documents_work_request_key');
            $table->dropColumn(['work_request_key_hash', 'work_payload_hash']);
        });
        Schema::dropIfExists('employee_ticket_comments');
        Schema::dropIfExists('employee_ticket_events');
        Schema::dropIfExists('employee_tickets');
        Schema::dropIfExists('employee_task_comments');
        Schema::dropIfExists('employee_task_events');
        Schema::dropIfExists('employee_tasks');
    }
};
