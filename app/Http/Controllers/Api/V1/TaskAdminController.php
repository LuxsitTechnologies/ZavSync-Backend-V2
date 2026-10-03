<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeTask;
use App\Services\Work\EmployeeWorkAccess;
use App\Services\Work\EmployeeWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskAdminController extends Controller
{
    public function __construct(private readonly EmployeeWorkAccess $access, private readonly EmployeeWorkService $work) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->access->company($request, 'tasks.view');
        $data = $request->validate(['assigned_employee_id' => ['sometimes', 'uuid'], 'status' => ['sometimes', 'in:ASSIGNED,IN_PROGRESS,COMPLETED,CANCELLED'], 'per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $query = EmployeeTask::query()->where('company_id', $companyId);
        foreach (['assigned_employee_id', 'status'] as $filter) {
            if (isset($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }
        $page = $query->orderBy('due_date')->orderBy('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (EmployeeTask $task): array => $this->work->presentTask($task, true))->all(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->access->company($request, 'tasks.manage');
        $this->access->company($request, 'tasks.assign');
        $data = $request->validate(['assigned_employee_id' => ['required', 'uuid'], 'title' => ['required', 'string', 'min:3', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'priority' => ['required', 'in:LOW,NORMAL,HIGH'], 'due_date' => ['required', 'date_format:Y-m-d']]);
        $task = $this->work->createTask($request, $companyId, $data, (string) $request->header('Idempotency-Key'));

        return response()->json($this->work->presentTask($task, true), $task->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $task): JsonResponse
    {
        $companyId = $this->access->company($request, 'tasks.view');

        return response()->json($this->work->presentTask($this->access->task($companyId, $task), true));
    }

    public function update(Request $request, string $task): JsonResponse
    {
        $companyId = $this->access->company($request, 'tasks.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'title' => ['sometimes', 'string', 'min:3', 'max:255'], 'description' => ['sometimes', 'nullable', 'string', 'max:10000'], 'priority' => ['sometimes', 'in:LOW,NORMAL,HIGH'], 'due_date' => ['sometimes', 'date_format:Y-m-d']]);

        return response()->json($this->work->presentTask($this->work->updateTask($request, $companyId, $task, $data), true));
    }

    public function assign(Request $request, string $task): JsonResponse
    {
        $companyId = $this->access->company($request, 'tasks.assign');
        $data = $request->validate(['assigned_employee_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1']]);

        return response()->json($this->work->presentTask($this->work->assignTask($request, $companyId, $task, $data['assigned_employee_id'], $data['version']), true));
    }

    public function start(Request $request, string $task): JsonResponse
    {
        return $this->transition($request, $task, 'START');
    }

    public function complete(Request $request, string $task): JsonResponse
    {
        return $this->transition($request, $task, 'COMPLETE');
    }

    public function reopen(Request $request, string $task): JsonResponse
    {
        return $this->transition($request, $task, 'REOPEN');
    }

    public function cancel(Request $request, string $task): JsonResponse
    {
        return $this->transition($request, $task, 'CANCEL');
    }

    public function comments(Request $request, string $task): JsonResponse
    {
        $companyId = $this->access->company($request, 'tasks.view');
        $model = $this->access->task($companyId, $task);

        return response()->json(['data' => $model->comments()->with('author')->orderBy('created_at')->orderBy('id')->get()->map(fn ($comment): array => $this->work->presentComment($comment))->all()]);
    }

    public function comment(Request $request, string $task): JsonResponse
    {
        $companyId = $this->access->company($request, 'tasks.manage');
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:5000']]);
        $comment = $this->work->commentTask($request, $companyId, $task, $data['body'], (string) $request->header('Idempotency-Key'));

        return response()->json($this->work->presentComment($comment->load('author')), $comment->wasRecentlyCreated ? 201 : 200);
    }

    private function transition(Request $request, string $task, string $action): JsonResponse
    {
        $companyId = $this->access->company($request, 'tasks.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return response()->json($this->work->presentTask($this->work->transitionTask($request, $companyId, $task, $action, $data['version']), true));
    }
}
