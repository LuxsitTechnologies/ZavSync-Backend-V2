<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeTicket;
use App\Services\Work\EmployeeWorkAccess;
use App\Services\Work\EmployeeWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeTicketController extends Controller
{
    public function __construct(private readonly EmployeeWorkAccess $access, private readonly EmployeeWorkService $work) {}

    public function index(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->access->employee($request, 'employee.tickets.view');
        $data = $request->validate(['status' => ['sometimes', 'in:OPEN,IN_PROGRESS,RESOLVED,CLOSED'], 'per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $query = EmployeeTicket::query()->where('company_id', $companyId)->where('employee_id', $employee->id);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (EmployeeTicket $ticket): array => $this->work->presentTicket($ticket))->all(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function store(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->access->employee($request, 'employee.tickets.create', true);
        $data = $request->validate(['subject' => ['required', 'string', 'min:3', 'max:255'], 'description' => ['required', 'string', 'min:3', 'max:10000'], 'category' => ['nullable', 'string', 'max:120'], 'priority' => ['required', 'in:LOW,NORMAL,HIGH']]);
        $ticket = $this->work->createTicket($request, $companyId, $employee->id, $data, (string) $request->header('Idempotency-Key'));

        return response()->json($this->work->presentTicket($ticket), $ticket->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        [$companyId, $employee] = $this->access->employee($request, 'employee.tickets.view');

        return response()->json($this->work->presentTicket($this->access->ticket($companyId, $ticket, $employee->id)));
    }

    public function close(Request $request, string $ticket): JsonResponse
    {
        [$companyId, $employee] = $this->access->employee($request, 'employee.tickets.comment', true);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return response()->json($this->work->presentTicket($this->work->transitionTicket($request, $companyId, $ticket, 'CLOSE', $data['version'], $employee->id)));
    }

    public function comments(Request $request, string $ticket): JsonResponse
    {
        [$companyId, $employee] = $this->access->employee($request, 'employee.tickets.view');
        $model = $this->access->ticket($companyId, $ticket, $employee->id);

        return response()->json(['data' => $model->comments()->with('author')->orderBy('created_at')->orderBy('id')->get()->map(fn ($comment): array => $this->work->presentComment($comment))->all()]);
    }

    public function comment(Request $request, string $ticket): JsonResponse
    {
        [$companyId, $employee] = $this->access->employee($request, 'employee.tickets.comment', true);
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:5000']]);
        $comment = $this->work->commentTicket($request, $companyId, $ticket, $data['body'], (string) $request->header('Idempotency-Key'), $employee->id);

        return response()->json($this->work->presentComment($comment->load('author')), $comment->wasRecentlyCreated ? 201 : 200);
    }
}
