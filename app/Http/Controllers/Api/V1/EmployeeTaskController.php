<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeTask;
use App\Services\Work\EmployeeWorkAccess;
use App\Services\Work\EmployeeWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeTaskController extends Controller
{
    public function __construct(private readonly EmployeeWorkAccess $access, private readonly EmployeeWorkService $work) {}

    public function index(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->access->employee($request, 'employee.tasks.view');
        $data = $request->validate(['status' => ['sometimes', 'in:ASSIGNED,IN_PROGRESS,COMPLETED,CANCELLED'], 'per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $query = EmployeeTask::query()->where('company_id', $companyId)->where('assigned_employee_id', $employee->id);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        $page = $query->orderBy('due_date')->orderBy('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $this->work->presentTasks($page->getCollection()), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, string $task): JsonResponse
    {
        [$companyId, $employee] = $this->access->employee($request, 'employee.tasks.view');

        return response()->json($this->work->presentTask($this->access->task($companyId, $task, $employee->id)));
    }

    public function start(Request $request, string $task): JsonResponse
    {
        return $this->transition($request, $task, 'START');
    }

    public function complete(Request $request, string $task): JsonResponse
    {
        return $this->transition($request, $task, 'COMPLETE');
    }

    public function comments(Request $request, string $task): JsonResponse
    {
        [$companyId, $employee] = $this->access->employee($request, 'employee.tasks.view');
        $model = $this->access->task($companyId, $task, $employee->id);

        return response()->json(['data' => $model->comments()->with('author')->orderBy('created_at')->orderBy('id')->get()->map(fn ($comment): array => $this->work->presentComment($comment))->all()]);
    }

    public function comment(Request $request, string $task): JsonResponse
    {
        [$companyId, $employee] = $this->access->employee($request, 'employee.tasks.comment', true);
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:5000']]);
        $comment = $this->work->commentTask($request, $companyId, $task, $data['body'], (string) $request->header('Idempotency-Key'), $employee->id);

        return response()->json($this->work->presentComment($comment->load('author')), $comment->wasRecentlyCreated ? 201 : 200);
    }

    private function transition(Request $request, string $task, string $action): JsonResponse
    {
        [$companyId, $employee] = $this->access->employee($request, 'employee.tasks.update', true);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $result = $this->work->transitionTask($request, $companyId, $task, $action, $data['version'], $employee->id);

        return response()->json($this->work->presentTask($result));
    }
}
