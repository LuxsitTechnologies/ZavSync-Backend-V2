<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiUsageRecord;
use App\Models\CompanyEntitlement;
use App\Models\Subscription;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiUsageController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'ai.usage.view');
        $from = $request->date('from')?->startOfDay() ?? now()->startOfMonth();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();
        $query = AiUsageRecord::query()->where('company_id', $companyId)->whereBetween('occurred_at', [$from, $to]);
        $records = (clone $query)->latest('occurred_at')->paginate(50);
        $summary = ['requests' => (clone $query)->count(), 'input_tokens' => (int) (clone $query)->sum('input_tokens'), 'output_tokens' => (int) (clone $query)->sum('output_tokens'), 'cost_minor' => (int) (clone $query)->sum('cost_minor')];

        $subscriptionLimits = Subscription::query()->with('plan:id,usage_limits')->where('company_id', $companyId)->latest('starts_at')->first()?->plan?->usage_limits ?? [];
        $overrideLimits = CompanyEntitlement::query()->where('company_id', $companyId)->where('module_key', 'ai')->value('limits') ?? [];

        return response()->json(['summary' => $summary, 'limits' => [...$subscriptionLimits, ...$overrideLimits], 'records' => $records]);
    }
}
