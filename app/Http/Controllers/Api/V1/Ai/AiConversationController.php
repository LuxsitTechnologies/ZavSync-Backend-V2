<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Contracts\AiToolRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Ai\StoreAiConversationRequest;
use App\Http\Requests\Api\V1\Ai\StoreAiMessageRequest;
use App\Models\AiConversation;
use App\Services\Ai\CopilotService;
use App\Services\AuditService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiConversationController extends Controller
{
    public function __construct(private readonly CopilotService $copilot, private readonly AiToolRegistry $tools, private readonly PlatformAccessService $access, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'ai.copilot.use');

        return response()->json(AiConversation::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)->whereNull('archived_at')->withCount('messages')->latest('updated_at')->paginate(30));
    }

    public function store(StoreAiConversationRequest $request): JsonResponse
    {
        $conversation = AiConversation::query()->create(['company_id' => $this->companyId($request), 'user_id' => $request->user()->id, 'title' => $request->validated('title') ?: 'New conversation']);

        return response()->json($conversation, 201);
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'ai.copilot.use');
        $model = $this->conversation($request, $conversation)->load(['messages' => fn ($query) => $query->with(['citations.source', 'toolRuns', 'actionProposals'])->oldest()]);

        return response()->json($model);
    }

    public function message(StoreAiMessageRequest $request, string $conversation): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            return response()->json(['message' => 'An Idempotency-Key header is required.', 'error_code' => 'IDEMPOTENCY_KEY_REQUIRED'], 422);
        }
        $message = $this->copilot->respond($request, $request->user(), $this->companyId($request), $this->conversation($request, $conversation), $request->validated('content'), $key);

        return response()->json($message, 201);
    }

    public function archive(Request $request, string $conversation): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'ai.copilot.use');
        $model = $this->conversation($request, $conversation);
        $model->update(['archived_at' => now()]);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'ai_conversation_archived', 'ai', $model, null, ['id' => $model->id]);

        return response()->json(['message' => 'Conversation archived.']);
    }

    public function tools(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'ai.copilot.use');

        return response()->json(['data' => $this->tools->definitions($request->user(), $companyId)]);
    }

    private function conversation(Request $request, string $id): AiConversation
    {
        return AiConversation::query()->where('company_id', $this->companyId($request))->where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
