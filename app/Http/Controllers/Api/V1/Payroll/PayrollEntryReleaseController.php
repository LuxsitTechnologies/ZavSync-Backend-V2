<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Services\Payroll\PayrollEntryReleaseService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollEntryReleaseController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly PayrollEntryReleaseService $releases) {}

    public function store(Request $request, string $entry): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'payroll.release');
        $released = $this->releases->release($request, $companyId, $entry);

        return response()->json(['entry_id' => $released->id, 'released_at' => $released->released_at->toIso8601String(), 'released_by' => $released->released_by]);
    }
}
