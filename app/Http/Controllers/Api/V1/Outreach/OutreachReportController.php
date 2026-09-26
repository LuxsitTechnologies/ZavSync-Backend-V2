<?php

namespace App\Http\Controllers\Api\V1\Outreach;

use App\Contracts\OutreachAiAssistant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Outreach\OutreachAiDraftRequest;
use App\Services\Outreach\OutreachReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutreachReportController extends Controller
{
    use AuthorizesOutreachRequests;

    public function __construct(private readonly OutreachReportService $reports, private readonly OutreachAiAssistant $ai) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'outreach.reports.view');

        return response()->json(['data' => $this->reports->summary($this->companyId($request))]);
    }

    public function aiDraft(OutreachAiDraftRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->ai->draft($request->validated('prompt'), [...$request->validated('context', []), 'company_id' => $this->companyId($request), 'user_id' => $request->user()->id])]);
    }
}
