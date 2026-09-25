<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'platform.audit.view');
        $query = AuditLog::query()->with('user:id,name,email')->where('company_id', $companyId);
        foreach (['user_id', 'module', 'action', 'entity_type', 'entity_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from')->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to')->endOfDay());
        }

        return response()->json($query->latest()->paginate(min($request->integer('per_page', 30), 100)));
    }
}
