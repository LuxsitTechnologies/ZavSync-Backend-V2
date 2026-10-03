<?php

namespace Tests\Feature\Stage16;

use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\CompanyUser;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskComment;
use App\Models\EmployeeTaskEvent;
use App\Models\EmployeeTicket;
use App\Models\EmployeeTicketComment;
use App\Models\EmployeeTicketEvent;
use App\Models\Permission;
use App\Models\PlatformModule;
use App\Models\PlatformNotification;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeWorkTest extends TestCase
{
    use RefreshDatabase;

    private User $employeeUser;

    private User $adminUser;

    public function test_task_lifecycle_reassignment_reopen_and_completion_evidence(): void
    {
        [$company, $employee] = $this->context();
        $this->admin();
        $payload = ['assigned_employee_id' => $employee->id, 'title' => 'Review this request', 'description' => 'Please review.', 'priority' => 'NORMAL', 'due_date' => now()->addWeek()->toDateString()];
        $created = $this->postJson('/api/v1/tasks', $payload, $this->headers($company, 'task-create-1'))->assertCreated()->assertJsonPath('status', 'ASSIGNED');
        $taskId = $created->json('id');
        $this->postJson('/api/v1/tasks', $payload, $this->headers($company, 'task-create-1'))->assertOk()->assertJsonPath('id', $taskId);
        $this->assertDatabaseCount('employee_tasks', 1);
        $this->employee();
        $this->getJson("/api/v1/employee/tasks/{$taskId}", $this->headers($company))->assertOk()->assertJsonPath('version', 1);
        $this->postJson("/api/v1/employee/tasks/{$taskId}/complete", ['version' => 1], $this->headers($company))->assertConflict();
        $this->postJson("/api/v1/employee/tasks/{$taskId}/start", ['version' => 1], $this->headers($company))->assertOk()->assertJsonPath('status', 'IN_PROGRESS');
        $this->postJson("/api/v1/employee/tasks/{$taskId}/complete", ['version' => 1], $this->headers($company))->assertConflict()->assertJsonPath('error_code', 'WORK_VERSION_STALE');
        $this->postJson("/api/v1/employee/tasks/{$taskId}/complete", ['version' => 2], $this->headers($company))->assertOk()->assertJsonPath('status', 'COMPLETED');
        $this->assertNotNull(EmployeeTask::query()->findOrFail($taskId)->completed_at);
        $this->postJson("/api/v1/employee/tasks/{$taskId}/comments", ['body' => 'Too late'], $this->headers($company, 'late-comment'))->assertConflict();
        $this->admin();
        $this->postJson("/api/v1/tasks/{$taskId}/reopen", ['version' => 3], $this->headers($company))->assertOk()->assertJsonPath('status', 'ASSIGNED');
        $this->assertNull(EmployeeTask::query()->findOrFail($taskId)->completed_at);
        $other = Employee::factory()->for($company)->create();
        $this->postJson("/api/v1/tasks/{$taskId}/assign", ['assigned_employee_id' => $other->id, 'version' => 4], $this->headers($company))->assertOk()->assertJsonPath('assigned_employee_id', $other->id);
        $this->employee();
        $this->getJson("/api/v1/employee/tasks/{$taskId}", $this->headers($company))->assertNotFound();
        $this->assertDatabaseCount('employee_task_events', 5);
    }

    public function test_ticket_idempotency_comments_and_closed_state(): void
    {
        [$company] = $this->context();
        $payload = ['subject' => 'Need help', 'description' => 'Please investigate the issue.', 'priority' => 'NORMAL'];
        $created = $this->postJson('/api/v1/employee/tickets', $payload, $this->headers($company, 'ticket-create-1'))->assertCreated();
        $id = $created->json('id');
        $this->postJson('/api/v1/employee/tickets', $payload, $this->headers($company, 'ticket-create-1'))->assertOk()->assertJsonPath('id', $id);
        $this->postJson('/api/v1/employee/tickets', [...$payload, 'subject' => 'Changed'], $this->headers($company, 'ticket-create-1'))->assertConflict();
        $url = "/api/v1/employee/tickets/{$id}/comments";
        $this->postJson($url, ['body' => 'An update'], $this->headers($company, 'comment-1'))->assertCreated();
        $this->postJson($url, ['body' => 'An update'], $this->headers($company, 'comment-1'))->assertOk();
        $this->assertDatabaseCount('employee_ticket_comments', 1);
        $this->postJson("/api/v1/employee/tickets/{$id}/close", ['version' => 1], $this->headers($company))->assertOk()->assertJsonPath('status', 'CLOSED');
        $this->postJson($url, ['body' => 'After close'], $this->headers($company, 'comment-2'))->assertConflict();
        $this->admin();
        $this->postJson("/api/v1/tickets/{$id}/reopen", ['version' => 2], $this->headers($company))->assertOk()->assertJsonPath('status', 'OPEN');
        $this->postJson("/api/v1/tickets/{$id}/start", ['version' => 3], $this->headers($company))->assertOk()->assertJsonPath('status', 'IN_PROGRESS');
        $this->postJson("/api/v1/tickets/{$id}/resolve", ['version' => 4], $this->headers($company))->assertOk()->assertJsonPath('status', 'RESOLVED');
        $this->assertDatabaseCount('employee_ticket_events', 5);
    }

    public function test_identity_tenancy_former_employee_and_permission_boundaries(): void
    {
        [$company, $employee] = $this->context();
        $task = EmployeeTask::factory()->create(['company_id' => $company->id, 'assigned_employee_id' => $employee->id, 'created_by' => $this->adminUser->id]);
        $ticket = EmployeeTicket::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'created_by' => $this->employeeUser->id]);
        $this->getJson("/api/v1/employee/tasks/{$task->id}", $this->headers($company))->assertOk();
        $employee->update(['status' => 'resigned']);
        $this->getJson("/api/v1/employee/tasks/{$task->id}", $this->headers($company))->assertOk();
        $this->postJson("/api/v1/employee/tasks/{$task->id}/start", ['version' => 1], $this->headers($company))->assertForbidden();
        $employee->update(['status' => 'terminated']);
        $this->getJson("/api/v1/employee/tickets/{$ticket->id}", $this->headers($company))->assertOk();
        $this->postJson("/api/v1/employee/tasks/{$task->id}/start", ['version' => 1], $this->headers($company))->assertForbidden();
        $this->postJson('/api/v1/employee/tickets', ['subject' => 'Blocked', 'description' => 'Former employee', 'priority' => 'NORMAL'], $this->headers($company, 'former-create'))->assertForbidden();
        $otherCompany = Company::factory()->create();
        $otherEmployee = Employee::factory()->for($otherCompany)->create();
        $foreignTask = EmployeeTask::factory()->create(['company_id' => $otherCompany->id, 'assigned_employee_id' => $otherEmployee->id]);
        $this->getJson("/api/v1/employee/tasks/{$foreignTask->id}", $this->headers($company))->assertNotFound();
        $this->admin();
        $this->postJson("/api/v1/tasks/{$task->id}/assign", ['assigned_employee_id' => $otherEmployee->id, 'version' => 1], $this->headers($company))->assertNotFound();
        $this->getJson("/api/v1/tasks/{$foreignTask->id}", $this->headers($company))->assertNotFound();
        $this->assertSame($employee->id, $task->fresh()->assigned_employee_id);
    }

    public function test_private_attachments_are_parent_scoped_and_generic_document_access_is_blocked(): void
    {
        [$company, $employee] = $this->context();
        Storage::fake('local');
        $task = EmployeeTask::factory()->create(['company_id' => $company->id, 'assigned_employee_id' => $employee->id]);
        $url = "/api/v1/employee/tasks/{$task->id}/attachments";
        $first = $this->postJson($url, ['file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')], $this->headers($company, 'file-upload-1'))->assertCreated();
        $documentId = $first->json('id');
        $this->assertArrayNotHasKey('storage_key', $first->json());
        $this->getJson($url, $this->headers($company))->assertOk()->assertJsonCount(1, 'data');
        $this->get("{$url}/{$documentId}", $this->headers($company))->assertOk();
        $this->getJson("/api/v1/platform/documents/{$documentId}/download", $this->headers($company))->assertNotFound();
        $this->admin();
        $this->getJson('/api/v1/platform/documents', $this->headers($company))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/platform/documents/{$documentId}/download", $this->headers($company))->assertNotFound();
        $this->deleteJson("/api/v1/platform/documents/{$documentId}", [], $this->headers($company))->assertNotFound();
        $this->postJson('/api/v1/platform/documents', ['documentable_type' => 'employee_task', 'documentable_id' => $task->id, 'file' => UploadedFile::fake()->create('bypass.pdf', 10, 'application/pdf')], $this->headers($company))->assertNotFound();
        $this->employee();
        $this->assertDatabaseCount('documents', 1);
        $this->assertSame($task->id, Document::query()->findOrFail($documentId)->documentable_id);
        $this->postJson("/api/v1/employee/tasks/{$task->id}/complete", ['version' => 1], $this->headers($company))->assertConflict();
        $this->postJson("/api/v1/employee/tasks/{$task->id}/start", ['version' => 1], $this->headers($company))->assertOk();
        $this->postJson("/api/v1/employee/tasks/{$task->id}/complete", ['version' => 2], $this->headers($company))->assertOk();
        $this->postJson($url, ['file' => UploadedFile::fake()->create('later.pdf', 10, 'application/pdf')], $this->headers($company, 'file-upload-2'))->assertConflict();
    }

    public function test_work_has_no_financial_or_other_hr_side_effects_and_narrow_presentation(): void
    {
        [$company, $employee] = $this->context();
        $this->admin();
        $response = $this->postJson('/api/v1/tasks', ['assigned_employee_id' => $employee->id, 'title' => 'Complete onboarding', 'priority' => 'NORMAL', 'due_date' => now()->addWeek()->toDateString()], $this->headers($company, 'firewall-task'))->assertCreated();
        $this->assertEqualsCanonicalizing(['id', 'title', 'description', 'priority', 'due_date', 'status', 'completed_at', 'version', 'assigned_employee_id', 'created_at'], array_keys($response->json()));
        $this->employee();
        $this->postJson('/api/v1/employee/tickets', ['subject' => 'Need help', 'description' => 'A support request', 'priority' => 'NORMAL'], $this->headers($company, 'firewall-ticket'))->assertCreated();
        foreach (['payroll_entries', 'attendance_sessions', 'leave_requests', 'invoices', 'journals', 'journal_lines', 'customer_payments', 'bank_transactions', 'inventory_movements', 'crm_activities'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_unlinked_and_platform_admin_do_not_gain_employee_identity_or_task_authority(): void
    {
        [$company, $employee] = $this->context();
        $task = EmployeeTask::factory()->create(['company_id' => $company->id, 'assigned_employee_id' => $employee->id]);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $this->employeeUser->id)->firstOrFail()->forceFill(['employee_id' => null])->save();
        $this->getJson("/api/v1/employee/tasks/{$task->id}", $this->headers($company))->assertConflict()->assertJsonPath('error_code', 'EMPLOYEE_IDENTITY_NOT_LINKED');
        $this->adminUser->forceFill(['is_platform_admin' => true])->save();
        $this->admin();
        $this->getJson("/api/v1/employee/tasks/{$task->id}", $this->headers($company))->assertForbidden();
        $role = Role::query()->where('company_id', $company->id)->where('name', 'Work administrator')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('name', 'tasks.view')->firstOrFail()->id);
        $this->getJson("/api/v1/tasks/{$task->id}", $this->headers($company))->assertForbidden();
    }

    public function test_task_idempotency_assignment_permission_and_inactive_assignee(): void
    {
        [$company, $employee] = $this->context();
        $this->admin();
        $payload = ['assigned_employee_id' => $employee->id, 'title' => 'Prepare the report', 'priority' => 'NORMAL', 'due_date' => now()->addWeek()->toDateString()];
        $this->postJson('/api/v1/tasks', $payload, $this->headers($company, 'task-key-one'))->assertCreated();
        $this->postJson('/api/v1/tasks', [...$payload, 'title' => 'Different report'], $this->headers($company, 'task-key-one'))->assertConflict();
        $this->assertDatabaseCount('employee_tasks', 1);
        $employee->update(['status' => 'resigned']);
        $this->postJson('/api/v1/tasks', $payload, $this->headers($company, 'task-key-two'))->assertUnprocessable()->assertJsonPath('error_code', 'TASK_ASSIGNEE_INACTIVE');
        $role = Role::query()->where('company_id', $company->id)->where('name', 'Work administrator')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('name', 'tasks.assign')->firstOrFail()->id);
        $this->postJson('/api/v1/tasks', $payload, $this->headers($company, 'task-key-three'))->assertForbidden();
    }

    public function test_comments_and_attachments_are_scoped_to_parent_and_safe_to_retry(): void
    {
        [$company, $employee] = $this->context();
        Storage::fake('local');
        $task = EmployeeTask::factory()->create(['company_id' => $company->id, 'assigned_employee_id' => $employee->id]);
        $otherTask = EmployeeTask::factory()->create(['company_id' => $company->id, 'assigned_employee_id' => $employee->id]);
        $commentUrl = "/api/v1/employee/tasks/{$task->id}/comments";
        $commentId = $this->postJson($commentUrl, ['body' => 'First update'], $this->headers($company, 'task-comment-one'))->assertCreated()->json('id');
        $this->postJson($commentUrl, ['body' => 'First update'], $this->headers($company, 'task-comment-one'))->assertOk()->assertJsonPath('id', $commentId);
        $this->postJson($commentUrl, ['body' => 'Changed update'], $this->headers($company, 'task-comment-one'))->assertConflict();
        $this->getJson("/api/v1/employee/tasks/{$otherTask->id}/comments", $this->headers($company))->assertOk()->assertJsonCount(0, 'data');
        $uploadUrl = "/api/v1/employee/tasks/{$task->id}/attachments";
        $documentId = $this->postJson($uploadUrl, ['file' => UploadedFile::fake()->createWithContent('memo.txt', 'evidence')], $this->headers($company, 'task-file-one'))->assertCreated()->json('id');
        $this->postJson($uploadUrl, ['file' => UploadedFile::fake()->createWithContent('memo.txt', 'evidence')], $this->headers($company, 'task-file-one'))->assertOk()->assertJsonPath('id', $documentId);
        $this->postJson($uploadUrl, ['file' => UploadedFile::fake()->createWithContent('memo.txt', 'different')], $this->headers($company, 'task-file-one'))->assertConflict();
        $this->getJson("/api/v1/employee/tasks/{$otherTask->id}/attachments/{$documentId}", $this->headers($company))->assertNotFound();
        $this->postJson($uploadUrl, ['file' => UploadedFile::fake()->createWithContent('bad.exe', 'not allowed')], $this->headers($company, 'task-file-two'))->assertUnprocessable();
        $this->postJson($uploadUrl, ['file' => UploadedFile::fake()->create('too-big.pdf', (int) config('platform.document_max_kilobytes') + 1, 'application/pdf')], $this->headers($company, 'task-file-three'))->assertUnprocessable();
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_assignment_and_admin_ticket_response_notify_only_intended_recipient(): void
    {
        [$company, $employee] = $this->context();
        $this->admin();
        $payload = ['assigned_employee_id' => $employee->id, 'title' => 'Check notifications', 'priority' => 'NORMAL', 'due_date' => now()->addWeek()->toDateString()];
        $this->postJson('/api/v1/tasks', $payload, $this->headers($company, 'notify-task-one'))->assertCreated();
        $this->postJson('/api/v1/tasks', $payload, $this->headers($company, 'notify-task-one'))->assertOk();
        $this->assertSame(1, PlatformNotification::query()->where('recipient_id', $this->employeeUser->id)->where('channel', 'IN_APP')->count());
        $this->assertSame(0, PlatformNotification::query()->where('recipient_id', $this->adminUser->id)->count());
        $this->employee();
        $ticketId = $this->postJson('/api/v1/employee/tickets', ['subject' => 'Need help', 'description' => 'A support request', 'priority' => 'NORMAL'], $this->headers($company, 'notify-ticket-one'))->assertCreated()->json('id');
        $this->admin();
        $this->postJson("/api/v1/tickets/{$ticketId}/comments", ['body' => 'We are investigating'], $this->headers($company, 'notify-comment-one'))->assertCreated();
        $this->postJson("/api/v1/tickets/{$ticketId}/comments", ['body' => 'We are investigating'], $this->headers($company, 'notify-comment-one'))->assertOk();
        $this->assertSame(2, PlatformNotification::query()->where('recipient_id', $this->employeeUser->id)->where('channel', 'IN_APP')->count());
        $this->assertSame(0, PlatformNotification::query()->where('channel', 'EMAIL')->count());
    }

    public function test_ticket_ownership_and_cross_company_resources_are_not_disclosed(): void
    {
        [$company, $employee] = $this->context();
        Storage::fake('local');
        $ticket = EmployeeTicket::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
        $otherEmployee = Employee::factory()->for($company)->create();
        $otherTicket = EmployeeTicket::factory()->create(['company_id' => $company->id, 'employee_id' => $otherEmployee->id]);
        $this->getJson("/api/v1/employee/tickets/{$otherTicket->id}", $this->headers($company))->assertNotFound();
        $this->postJson("/api/v1/employee/tickets/{$otherTicket->id}/comments", ['body' => 'Intrusion'], $this->headers($company, 'intrusion-comment'))->assertNotFound();
        $this->postJson("/api/v1/employee/tickets/{$otherTicket->id}/close", ['version' => 1], $this->headers($company))->assertNotFound();
        $this->postJson("/api/v1/employee/tickets/{$otherTicket->id}/attachments", ['file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')], $this->headers($company, 'intrusion-file'))->assertNotFound();
        $foreignCompany = Company::factory()->create();
        $foreignEmployee = Employee::factory()->for($foreignCompany)->create();
        $foreignTicket = EmployeeTicket::factory()->create(['company_id' => $foreignCompany->id, 'employee_id' => $foreignEmployee->id]);
        $this->getJson("/api/v1/employee/tickets/{$foreignTicket->id}", $this->headers($company))->assertNotFound();
        $this->getJson("/api/v1/employee/tickets/{$ticket->id}", $this->headers($company))->assertOk();
        $this->assertDatabaseCount('employee_ticket_comments', 0);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_administrator_controls_task_content_and_cancellation_without_erasing_evidence(): void
    {
        [$company, $employee] = $this->context();
        $task = EmployeeTask::factory()->create(['company_id' => $company->id, 'assigned_employee_id' => $employee->id]);
        $this->admin();
        $this->patchJson("/api/v1/tasks/{$task->id}", ['version' => 1, 'title' => 'Updated title'], $this->headers($company))->assertOk()->assertJsonPath('version', 2);
        $this->postJson("/api/v1/tasks/{$task->id}/cancel", ['version' => 2], $this->headers($company))->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->patchJson("/api/v1/tasks/{$task->id}", ['version' => 3, 'title' => 'Should fail'], $this->headers($company))->assertConflict();
        $this->postJson("/api/v1/tasks/{$task->id}/reopen", ['version' => 3], $this->headers($company))->assertConflict();
        $this->employee();
        $this->getJson("/api/v1/employee/tasks/{$task->id}", $this->headers($company))->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->postJson("/api/v1/employee/tasks/{$task->id}/comments", ['body' => 'Cannot comment'], $this->headers($company, 'cancel-comment'))->assertConflict();
        $this->assertDatabaseCount('employee_task_events', 2);
    }

    public function test_payroll_permission_does_not_grant_work_access_and_commercial_gate_still_applies(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['payroll.view', 'employee.payroll.view']);
        $employee = Employee::factory()->for($company)->create(['created_by' => $user->id]);
        $membership = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail();
        $membership->forceFill(['employee_id' => $employee->id])->save();
        $this->getJson('/api/v1/employee/tasks', $this->headers($company))->assertForbidden();
        $this->getJson('/api/v1/tasks', $this->headers($company))->assertForbidden();
        $this->postJson('/api/v1/employee/tickets', ['subject' => 'Blocked', 'description' => 'No work authority', 'priority' => 'NORMAL'], $this->headers($company, 'no-authority'))->assertForbidden();
        $membership->role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'employee.tasks.view']));
        $this->getJson('/api/v1/employee/tasks', $this->headers($company))->assertOk();
        PlatformModule::query()->firstOrCreate(['key' => 'payroll'], ['name' => 'Payroll']);
        CompanyEntitlement::factory()->for($company)->create(['module_key' => 'payroll', 'is_enabled' => false, 'updated_by' => $user->id]);
        app(EntitlementService::class)->forget($company->id);
        $this->getJson('/api/v1/employee/tasks', $this->headers($company))->assertForbidden()->assertJsonPath('error_code', 'MODULE_NOT_ENTITLED');
    }

    public function test_work_factories_preserve_parent_company_for_events_and_comments(): void
    {
        [$company, $employee] = $this->context();
        $task = EmployeeTask::factory()->create(['company_id' => $company->id, 'assigned_employee_id' => $employee->id]);
        $ticket = EmployeeTicket::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
        $taskEvent = EmployeeTaskEvent::factory()->create(['employee_task_id' => $task->id]);
        $taskComment = EmployeeTaskComment::factory()->create(['employee_task_id' => $task->id]);
        $ticketEvent = EmployeeTicketEvent::factory()->create(['employee_ticket_id' => $ticket->id]);
        $ticketComment = EmployeeTicketComment::factory()->create(['employee_ticket_id' => $ticket->id]);
        foreach ([$taskEvent, $taskComment, $ticketEvent, $ticketComment] as $evidence) {
            $this->assertSame($company->id, $evidence->company_id);
        }
        $this->assertDatabaseCount('employee_task_events', 1);
        $this->assertDatabaseCount('employee_ticket_comments', 1);
    }

    /** @return array{Company, Employee} */
    private function context(): array
    {
        [$user, $company] = $this->actingAsCompanyUser(['employee.tasks.view', 'employee.tasks.update', 'employee.tasks.comment', 'employee.tickets.view', 'employee.tickets.create', 'employee.tickets.comment']);
        $this->employeeUser = $user;
        $employee = Employee::factory()->for($company)->create(['created_by' => $user->id]);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail()->forceFill(['employee_id' => $employee->id])->save();
        $admin = User::factory()->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Work administrator']);
        foreach (['tasks.view', 'tasks.manage', 'tasks.assign', 'tickets.view', 'tickets.manage', 'platform.documents.view', 'platform.documents.manage'] as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission]));
        }
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'is_active' => true]);
        $this->adminUser = $admin;

        return [$company, $employee];
    }

    private function employee(): void
    {
        Sanctum::actingAs($this->employeeUser);
    }

    private function admin(): void
    {
        Sanctum::actingAs($this->adminUser);
    }

    /** @return array<string, string> */
    private function headers(Company $company, ?string $key = null): array
    {
        return array_filter(['X-Company-Id' => $company->id, 'Accept' => 'application/json', 'Idempotency-Key' => $key]);
    }
}
