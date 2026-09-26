<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Ai\UpdatePrioritySignalRequest;
use App\Models\OperationalPrioritySignal;
use App\Services\Ai\OperationalSignalService;
use App\Services\AuditService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrioritySignalController extends Controller
{
    public function __construct(private readonly OperationalSignalService $signals, private readonly PlatformAccessService $access, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'intelligence.view');
        $query = OperationalPrioritySignal::query()->where('company_id', $companyId)->with('assignedUser:id,name,email');
        $permissions = $this->access->permissionNames($request->user(), $companyId);
        if (! in_array('*', $permissions, true)) {
            $modules = collect(OperationalSignalService::SOURCE_PERMISSIONS)->filter(fn (string $permission): bool => in_array($permission, $permissions, true))->keys();
            $query->whereIn('source_module', $modules);
        }
        foreach (['status', 'category', 'source_module', 'severity'] as $filter) {
            if ($request->filled($filter)) {
                $value = $request->string($filter)->toString();
                $query->where($filter, $filter === 'source_module' ? mb_strtolower($value) : mb_strtoupper($value));
            }
        }
        if ($request->filled('assigned_user_id')) {
            $query->where('assigned_user_id', $request->integer('assigned_user_id'));
        }
        $summary = (clone $query)->selectRaw('status, severity, count(*) as count')->groupBy('status', 'severity')->get();

        return response()->json(['summary' => $summary, 'signals' => $query->orderByDesc('priority_score')->orderByDesc('detected_at')->paginate(50)]);
    }

    public function show(Request $request, string $signal): JsonResponse
    {
        return response()->json($this->signal($request, $signal)->load(['assignedUser:id,name,email', 'events.actor:id,name']));
    }

    public function update(UpdatePrioritySignalRequest $request, string $signal): JsonResponse
    {
        $model = $this->signal($request, $signal);
        $old = $model->withoutRelations()->toArray();
        $updated = $this->signals->transition($this->companyId($request), $request->user(), $model, $request->validated());
        $this->audit->record($request, $request->user(), $this->companyId($request), 'update_priority_signal', 'ai', $updated, $old, $updated->withoutRelations()->toArray());

        return response()->json($updated);
    }

    private function signal(Request $request, string $id): OperationalPrioritySignal
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'intelligence.view');
        $signal = OperationalPrioritySignal::query()->where('company_id', $companyId)->findOrFail($id);
        $permission = $signal->explanation_metadata['required_permission'] ?? 'intelligence.view';
        abort_unless($request->user()->hasCompanyPermission($companyId, $permission), 404);

        return $signal;
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
