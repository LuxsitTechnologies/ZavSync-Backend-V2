<?php

namespace App\Services\Work;

use App\Exceptions\PlatformException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskComment;
use App\Models\EmployeeTaskEvent;
use App\Models\EmployeeTicket;
use App\Models\EmployeeTicketComment;
use App\Models\EmployeeTicketEvent;
use App\Services\AuditService;
use App\Services\Platform\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EmployeeWorkService
{
    public function __construct(private readonly AuditService $audit, private readonly NotificationService $notifications) {}

    public function keyHash(string $key): string
    {
        if (mb_strlen($key) < 8 || mb_strlen($key) > 200) {
            throw new PlatformException('WORK_IDEMPOTENCY_KEY_REQUIRED', 'An Idempotency-Key of 8 to 200 characters is required.', 422);
        }

        return hash('sha256', $key);
    }

    /** @param array<string, mixed> $data */
    public function payloadHash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data */
    public function createTask(Request $request, string $companyId, array $data, string $key): EmployeeTask
    {
        $keyHash = $this->keyHash($key);
        $payloadHash = $this->payloadHash($data);

        return DB::transaction(function () use ($request, $companyId, $data, $keyHash, $payloadHash): EmployeeTask {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $prior = EmployeeTask::query()->where('company_id', $companyId)->where('request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                $this->assertReplay($prior->payload_hash, $payloadHash);

                return $prior;
            }
            $this->assertAssignable(Employee::query()->where('company_id', $companyId)->findOrFail($data['assigned_employee_id']));
            $task = EmployeeTask::query()->create([
                'company_id' => $companyId, 'assigned_employee_id' => $data['assigned_employee_id'],
                'created_by' => $request->user()->id, 'title' => $data['title'], 'description' => $data['description'] ?? null,
                'priority' => $data['priority'], 'due_date' => $data['due_date'], 'status' => 'ASSIGNED',
                'version' => 1, 'request_key_hash' => $keyHash, 'payload_hash' => $payloadHash,
            ]);
            $this->taskEvent($task, $request, 'CREATED', null, 'ASSIGNED', null, $task->assigned_employee_id);
            $this->audit->record($request, $request->user(), $companyId, 'employee_task_created', 'employee_work', $task, null, ['assigned_employee_id' => $task->assigned_employee_id]);
            $this->notifyEmployee($task->company_id, $task->assigned_employee_id, $request->user()->id, 'task.assigned', 'Task assigned', $task->title);

            return $task;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createTicket(Request $request, string $companyId, string $employeeId, array $data, string $key): EmployeeTicket
    {
        $keyHash = $this->keyHash($key);
        $payloadHash = $this->payloadHash($data);

        return DB::transaction(function () use ($request, $companyId, $employeeId, $data, $keyHash, $payloadHash): EmployeeTicket {
            Employee::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($employeeId);
            $prior = EmployeeTicket::query()->where('company_id', $companyId)->where('employee_id', $employeeId)->where('request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                $this->assertReplay($prior->payload_hash, $payloadHash);

                return $prior;
            }
            $ticket = EmployeeTicket::query()->create([
                'company_id' => $companyId, 'employee_id' => $employeeId, 'created_by' => $request->user()->id,
                'subject' => $data['subject'], 'description' => $data['description'], 'category' => $data['category'] ?? null,
                'priority' => $data['priority'], 'status' => 'OPEN', 'version' => 1,
                'request_key_hash' => $keyHash, 'payload_hash' => $payloadHash,
            ]);
            $this->ticketEvent($ticket, $request, 'CREATED', null, 'OPEN');
            $this->audit->record($request, $request->user(), $companyId, 'employee_ticket_created', 'employee_work', $ticket, null, ['status' => 'OPEN']);

            return $ticket;
        }, 3);
    }

    public function transitionTask(Request $request, string $companyId, string $taskId, string $action, int $version, ?string $employeeId = null): EmployeeTask
    {
        return DB::transaction(function () use ($request, $companyId, $taskId, $action, $version, $employeeId): EmployeeTask {
            $query = EmployeeTask::query()->where('company_id', $companyId);
            if ($employeeId !== null) {
                $query->where('assigned_employee_id', $employeeId);
            }
            $task = $query->lockForUpdate()->findOrFail($taskId);
            $this->assertVersion($task->version, $version);
            [$from, $to] = match ($action) {
                'START' => ['ASSIGNED', 'IN_PROGRESS'], 'COMPLETE' => ['IN_PROGRESS', 'COMPLETED'],
                'REOPEN' => ['COMPLETED', 'ASSIGNED'], 'CANCEL' => ['ASSIGNED,IN_PROGRESS', 'CANCELLED'],
                default => throw new PlatformException('TASK_ACTION_INVALID', 'Unsupported task action.', 422),
            };
            if (! in_array($task->status, explode(',', $from), true)) {
                throw new PlatformException('TASK_TRANSITION_INVALID', 'This task cannot make that transition.', 409);
            }
            $prior = $task->status;
            $task->update(['status' => $to, 'completed_at' => $to === 'COMPLETED' ? now() : null, 'version' => $task->version + 1]);
            $this->taskEvent($task, $request, $action, $prior, $to);
            $this->audit->record($request, $request->user(), $companyId, 'employee_task_'.mb_strtolower($action), 'employee_work', $task, ['status' => $prior], ['status' => $to]);
            if ($action === 'REOPEN') {
                $this->notifyEmployee($companyId, $task->assigned_employee_id, $request->user()->id, 'task.reopened', 'Task reopened', $task->title);
            }

            return $task;
        }, 3);
    }

    public function assignTask(Request $request, string $companyId, string $taskId, string $newEmployeeId, int $version): EmployeeTask
    {
        return DB::transaction(function () use ($request, $companyId, $taskId, $newEmployeeId, $version): EmployeeTask {
            $task = EmployeeTask::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($taskId);
            $this->assertVersion($task->version, $version);
            if (! in_array($task->status, ['ASSIGNED', 'IN_PROGRESS'], true)) {
                throw new PlatformException('TASK_ASSIGNMENT_CLOSED', 'Only active tasks may be reassigned.', 409);
            }
            $this->assertAssignable(Employee::query()->where('company_id', $companyId)->findOrFail($newEmployeeId));
            if ($task->assigned_employee_id === $newEmployeeId) {
                throw new PlatformException('TASK_ALREADY_ASSIGNED', 'Task is already assigned to this employee.', 409);
            }
            $oldEmployeeId = $task->assigned_employee_id;
            $task->update(['assigned_employee_id' => $newEmployeeId, 'version' => $task->version + 1]);
            $this->taskEvent($task, $request, 'REASSIGNED', $task->status, $task->status, $oldEmployeeId, $newEmployeeId);
            $this->audit->record($request, $request->user(), $companyId, 'employee_task_reassigned', 'employee_work', $task, ['employee_id' => $oldEmployeeId], ['employee_id' => $newEmployeeId]);
            $this->notifyEmployee($companyId, $newEmployeeId, $request->user()->id, 'task.assigned', 'Task assigned', $task->title);

            return $task;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateTask(Request $request, string $companyId, string $taskId, array $data): EmployeeTask
    {
        return DB::transaction(function () use ($request, $companyId, $taskId, $data): EmployeeTask {
            $task = EmployeeTask::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($taskId);
            $this->assertVersion($task->version, $data['version']);
            if (! in_array($task->status, ['ASSIGNED', 'IN_PROGRESS'], true)) {
                throw new PlatformException('TASK_CONTENT_CLOSED', 'Reopen this task before editing it.', 409);
            }
            $old = $task->only(['title', 'description', 'priority', 'due_date']);
            $changes = array_intersect_key($data, array_flip(['title', 'description', 'priority', 'due_date']));
            if ($changes === []) {
                throw new PlatformException('TASK_CHANGES_REQUIRED', 'No task changes were supplied.', 422);
            }
            $task->update([...$changes, 'version' => $task->version + 1]);
            $this->taskEvent($task, $request, 'UPDATED', $task->status, $task->status);
            $this->audit->record($request, $request->user(), $companyId, 'employee_task_updated', 'employee_work', $task, $old, $changes);

            return $task;
        }, 3);
    }

    public function transitionTicket(Request $request, string $companyId, string $ticketId, string $action, int $version, ?string $employeeId = null): EmployeeTicket
    {
        return DB::transaction(function () use ($request, $companyId, $ticketId, $action, $version, $employeeId): EmployeeTicket {
            $query = EmployeeTicket::query()->where('company_id', $companyId);
            if ($employeeId !== null) {
                $query->where('employee_id', $employeeId);
            }
            $ticket = $query->lockForUpdate()->findOrFail($ticketId);
            $this->assertVersion($ticket->version, $version);
            [$from, $to] = match ($action) {
                'START' => ['OPEN', 'IN_PROGRESS'], 'RESOLVE' => ['IN_PROGRESS', 'RESOLVED'],
                'CLOSE' => ['OPEN,IN_PROGRESS,RESOLVED', 'CLOSED'], 'REOPEN' => ['CLOSED,RESOLVED', 'OPEN'],
                default => throw new PlatformException('TICKET_ACTION_INVALID', 'Unsupported ticket action.', 422),
            };
            if (! in_array($ticket->status, explode(',', $from), true)) {
                throw new PlatformException('TICKET_TRANSITION_INVALID', 'This ticket cannot make that transition.', 409);
            }
            $old = $ticket->status;
            $ticket->update(['status' => $to, 'version' => $ticket->version + 1]);
            $this->ticketEvent($ticket, $request, $action, $old, $to);
            $this->audit->record($request, $request->user(), $companyId, 'employee_ticket_'.mb_strtolower($action), 'employee_work', $ticket, ['status' => $old], ['status' => $to]);
            if ($employeeId === null) {
                $this->notifyEmployee($companyId, $ticket->employee_id, $request->user()->id, 'ticket.status_changed', 'Ticket updated', 'Your support ticket status changed.');
            }

            return $ticket;
        }, 3);
    }

    public function commentTask(Request $request, string $companyId, string $taskId, string $body, string $key, ?string $employeeId = null): EmployeeTaskComment
    {
        return DB::transaction(function () use ($request, $companyId, $taskId, $body, $key, $employeeId): EmployeeTaskComment {
            $query = EmployeeTask::query()->where('company_id', $companyId);
            if ($employeeId !== null) {
                $query->where('assigned_employee_id', $employeeId);
            }
            $task = $query->lockForUpdate()->findOrFail($taskId);
            $keyHash = $this->keyHash($key);
            $payloadHash = $this->payloadHash(['body' => $body, 'author_id' => $request->user()->id]);
            $prior = $task->comments()->where('request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                $this->assertReplay($prior->payload_hash, $payloadHash);

                return $prior;
            }
            if (! in_array($task->status, ['ASSIGNED', 'IN_PROGRESS'], true)) {
                throw new PlatformException('TASK_COMMENT_CLOSED', 'This task is closed to comments.', 409);
            }
            $comment = $task->comments()->create(['company_id' => $companyId, 'author_id' => $request->user()->id, 'body' => $body, 'request_key_hash' => $keyHash, 'payload_hash' => $payloadHash, 'created_at' => now()]);
            $this->audit->record($request, $request->user(), $companyId, 'employee_task_commented', 'employee_work', $task, null, ['comment_id' => $comment->id]);

            return $comment;
        }, 3);
    }

    public function commentTicket(Request $request, string $companyId, string $ticketId, string $body, string $key, ?string $employeeId = null): EmployeeTicketComment
    {
        return DB::transaction(function () use ($request, $companyId, $ticketId, $body, $key, $employeeId): EmployeeTicketComment {
            $query = EmployeeTicket::query()->where('company_id', $companyId);
            if ($employeeId !== null) {
                $query->where('employee_id', $employeeId);
            }
            $ticket = $query->lockForUpdate()->findOrFail($ticketId);
            $keyHash = $this->keyHash($key);
            $payloadHash = $this->payloadHash(['body' => $body, 'author_id' => $request->user()->id]);
            $prior = $ticket->comments()->where('request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                $this->assertReplay($prior->payload_hash, $payloadHash);

                return $prior;
            }
            if (! in_array($ticket->status, ['OPEN', 'IN_PROGRESS', 'RESOLVED'], true)) {
                throw new PlatformException('TICKET_COMMENT_CLOSED', 'This ticket is closed to comments.', 409);
            }
            $comment = $ticket->comments()->create(['company_id' => $companyId, 'author_id' => $request->user()->id, 'body' => $body, 'request_key_hash' => $keyHash, 'payload_hash' => $payloadHash, 'created_at' => now()]);
            $this->audit->record($request, $request->user(), $companyId, 'employee_ticket_commented', 'employee_work', $ticket, null, ['comment_id' => $comment->id]);
            if ($employeeId === null) {
                $this->notifyEmployee($companyId, $ticket->employee_id, $request->user()->id, 'ticket.responded', 'Ticket response', 'Your support ticket has a new response.');
            }

            return $comment;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function presentTask(EmployeeTask $task, bool $admin = false): array
    {
        $assigneeName = Employee::query()->where('company_id', $task->company_id)->whereKey($task->assigned_employee_id)->value('full_name');
        $creatorName = CompanyUser::query()->with('user:id,name')->where('company_id', $task->company_id)->where('user_id', $task->created_by)->first()?->user?->name;

        return $this->taskPresentation($task, $admin, $creatorName, $assigneeName);
    }

    /** @param Collection<int, EmployeeTask> $tasks @return array<int, array<string, mixed>> */
    public function presentTasks(Collection $tasks, bool $admin = false): array
    {
        $labels = [];
        foreach ($tasks->groupBy('company_id') as $companyId => $companyTasks) {
            $assignees = Employee::query()->where('company_id', $companyId)
                ->whereIn('id', $companyTasks->pluck('assigned_employee_id')->unique())->pluck('full_name', 'id');
            $creators = CompanyUser::query()->with('user:id,name')->where('company_id', $companyId)
                ->whereIn('user_id', $companyTasks->pluck('created_by')->unique())->get()->keyBy('user_id');
            foreach ($companyTasks as $task) {
                $labels[$task->id] = [
                    $creators->get($task->created_by)?->user?->name,
                    $assignees->get($task->assigned_employee_id),
                ];
            }
        }

        return $tasks->map(fn (EmployeeTask $task): array => $this->taskPresentation($task, $admin, ...$labels[$task->id]))->all();
    }

    /** @return array<string, mixed> */
    private function taskPresentation(EmployeeTask $task, bool $admin, ?string $creatorName, ?string $assigneeName): array
    {
        return ['id' => $task->id, 'title' => $task->title, 'description' => $task->description,
            'priority' => $task->priority, 'due_date' => $task->due_date->toDateString(), 'status' => $task->status,
            'completed_at' => $task->completed_at?->toIso8601String(), 'version' => $task->version,
            'assigned_employee_id' => $admin ? $task->assigned_employee_id : null,
            'creator_name' => $creatorName ?? 'Unavailable user',
            'assignee_name' => $assigneeName ?? 'Unavailable employee',
            'created_at' => $task->created_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function presentTicket(EmployeeTicket $ticket, bool $admin = false): array
    {
        return ['id' => $ticket->id, 'subject' => $ticket->subject, 'description' => $ticket->description,
            'category' => $ticket->category, 'priority' => $ticket->priority, 'status' => $ticket->status,
            'version' => $ticket->version, 'employee_id' => $admin ? $ticket->employee_id : null,
            'created_at' => $ticket->created_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function presentComment(EmployeeTaskComment|EmployeeTicketComment $comment): array
    {
        return ['id' => $comment->id, 'body' => $comment->body,
            'author' => $comment->author?->name ?? 'Former member', 'created_at' => $comment->created_at?->toIso8601String()];
    }

    private function assertVersion(int $current, int $requested): void
    {
        if ($current !== $requested) {
            throw new PlatformException('WORK_VERSION_STALE', 'This work item changed. Reload it before continuing.', 409);
        }
        if ($current >= 4_294_967_295) {
            throw new PlatformException('WORK_VERSION_EXHAUSTED', 'This work item cannot be changed further.', 409);
        }
    }

    private function assertReplay(string $existingHash, string $requestedHash): void
    {
        if (! hash_equals($existingHash, $requestedHash)) {
            throw new PlatformException('WORK_IDEMPOTENCY_CONFLICT', 'This idempotency key was used for different content.', 409);
        }
    }

    private function assertAssignable(Employee $employee): void
    {
        if (in_array(mb_strtolower($employee->status), ['resigned', 'terminated'], true)) {
            throw new PlatformException('TASK_ASSIGNEE_INACTIVE', 'A task cannot be assigned to a former employee.', 422);
        }
    }

    private function taskEvent(EmployeeTask $task, Request $request, string $action, ?string $from, string $to, ?string $fromEmployeeId = null, ?string $toEmployeeId = null): void
    {
        EmployeeTaskEvent::query()->create(['company_id' => $task->company_id, 'employee_task_id' => $task->id,
            'actor_id' => $request->user()->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to,
            'from_employee_id' => $fromEmployeeId, 'to_employee_id' => $toEmployeeId, 'created_at' => now()]);
    }

    private function ticketEvent(EmployeeTicket $ticket, Request $request, string $action, ?string $from, string $to): void
    {
        EmployeeTicketEvent::query()->create(['company_id' => $ticket->company_id, 'employee_ticket_id' => $ticket->id,
            'actor_id' => $request->user()->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to, 'created_at' => now()]);
    }

    private function notifyEmployee(string $companyId, string $employeeId, int $actorId, string $type, string $title, string $message): void
    {
        $recipient = CompanyUser::query()->where('company_id', $companyId)->where('employee_id', $employeeId)->where('is_active', true)->value('user_id');
        if ($recipient !== null && (int) $recipient !== $actorId) {
            $this->notifications->createInApp($companyId, (int) $recipient, $type, $title, $message);
        }
    }
}
