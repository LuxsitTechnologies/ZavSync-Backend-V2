<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeTicket;
use App\Services\Work\EmployeeWorkAccess;
use App\Services\Work\EmployeeWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketAdminController extends Controller
{
    public function __construct(private readonly EmployeeWorkAccess $access, private readonly EmployeeWorkService $work) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->access->company($request, 'tickets.view');
        $data = $request->validate(['employee_id' => ['sometimes', 'uuid'], 'status' => ['sometimes', 'in:OPEN,IN_PROGRESS,RESOLVED,CLOSED'], 'per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $query = EmployeeTicket::query()->where('company_id', $companyId);
        foreach (['employee_id', 'status'] as $filter) {
            if (isset($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (EmployeeTicket $ticket): array => $this->work->presentTicket($ticket, true))->all(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        $companyId = $this->access->company($request, 'tickets.view');

        return response()->json($this->work->presentTicket($this->access->ticket($companyId, $ticket), true));
    }

    public function start(Request $request, string $ticket): JsonResponse
    {
        return $this->transition($request, $ticket, 'START');
    }

    public function resolve(Request $request, string $ticket): JsonResponse
    {
        return $this->transition($request, $ticket, 'RESOLVE');
    }

    public function close(Request $request, string $ticket): JsonResponse
    {
        return $this->transition($request, $ticket, 'CLOSE');
    }

    public function reopen(Request $request, string $ticket): JsonResponse
    {
        return $this->transition($request, $ticket, 'REOPEN');
    }

    public function comments(Request $request, string $ticket): JsonResponse
    {
        $companyId = $this->access->company($request, 'tickets.view');
        $model = $this->access->ticket($companyId, $ticket);

        return response()->json(['data' => $model->comments()->with('author')->orderBy('created_at')->orderBy('id')->get()->map(fn ($comment): array => $this->work->presentComment($comment))->all()]);
    }

    public function comment(Request $request, string $ticket): JsonResponse
    {
        $companyId = $this->access->company($request, 'tickets.manage');
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:5000']]);
        $comment = $this->work->commentTicket($request, $companyId, $ticket, $data['body'], (string) $request->header('Idempotency-Key'));

        return response()->json($this->work->presentComment($comment->load('author')), $comment->wasRecentlyCreated ? 201 : 200);
    }

    private function transition(Request $request, string $ticket, string $action): JsonResponse
    {
        $companyId = $this->access->company($request, 'tickets.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return response()->json($this->work->presentTicket($this->work->transitionTicket($request, $companyId, $ticket, $action, $data['version']), true));
    }
}
