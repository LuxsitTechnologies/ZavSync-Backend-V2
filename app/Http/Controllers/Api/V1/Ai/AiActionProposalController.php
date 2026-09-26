<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Ai\StoreAiActionProposalRequest;
use App\Models\AiActionProposal;
use App\Services\Ai\AiActionProposalService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiActionProposalController extends Controller
{
    public function __construct(private readonly AiActionProposalService $actions, private readonly PlatformAccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'ai.actions.review');
        $query = AiActionProposal::query()->where('company_id', $companyId)->with('execution');
        $permissions = $this->access->permissionNames($request->user(), $companyId);
        if (! in_array('*', $permissions, true)) {
            $query->whereIn('required_permission', $permissions);
        }
        if ($request->filled('status')) {
            $query->where('status', mb_strtoupper($request->string('status')->toString()));
        }

        return response()->json($query->latest()->paginate(30));
    }

    public function store(StoreAiActionProposalRequest $request): JsonResponse
    {
        $proposal = $this->actions->propose($request, $request->user(), $this->companyId($request), $request->validated('action_type'), $request->validated('payload'), $this->idempotencyKey($request), $request->validated('conversation_id'), $request->validated('message_id'));

        return response()->json($proposal, 201);
    }

    public function show(Request $request, string $proposal): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'ai.actions.review');

        return response()->json($this->proposal($request, $proposal)->load('execution'));
    }

    public function approve(Request $request, string $proposal): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'ai.actions.approve');

        return response()->json($this->actions->approve($request, $request->user(), $this->companyId($request), $this->proposal($request, $proposal)));
    }

    public function reject(Request $request, string $proposal): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'ai.actions.approve');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json($this->actions->reject($request, $request->user(), $this->companyId($request), $this->proposal($request, $proposal), $data['reason']));
    }

    public function execute(Request $request, string $proposal): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'ai.actions.execute');

        return response()->json($this->actions->execute($request, $request->user(), $this->companyId($request), $this->proposal($request, $proposal), $this->idempotencyKey($request)));
    }

    private function proposal(Request $request, string $id): AiActionProposal
    {
        $proposal = AiActionProposal::query()->where('company_id', $this->companyId($request))->findOrFail($id);
        abort_unless($request->user()->hasCompanyPermission($proposal->company_id, $proposal->required_permission), 404);

        return $proposal;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            throw new PlatformException('IDEMPOTENCY_KEY_REQUIRED', 'An Idempotency-Key header is required.', 422);
        }

        return $key;
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
