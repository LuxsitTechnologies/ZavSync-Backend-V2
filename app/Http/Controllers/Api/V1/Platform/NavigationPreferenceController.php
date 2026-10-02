<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyNavigationPreference;
use App\Services\AuditService;
use App\Services\Platform\NavigationVisibilityService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NavigationPreferenceController extends Controller
{
    public function __construct(
        private readonly NavigationVisibilityService $navigation,
        private readonly PlatformAccessService $access,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');

        return response()->json($this->navigation->resolve(
            $companyId,
            $this->access->permissionNames($request->user(), $companyId),
        ));
    }

    public function update(Request $request, string $item): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'platform.settings.manage');
        abort_unless($this->navigation->hasItem($item), 404);
        $data = $request->validate(['is_visible' => ['required', 'boolean']]);

        DB::transaction(function () use ($request, $companyId, $item, $data): void {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $preference = CompanyNavigationPreference::query()->where('company_id', $companyId)->where('item_key', $item)->first();
            $oldValues = $preference === null ? null : ['is_visible' => $preference->is_visible];
            $preference = CompanyNavigationPreference::query()->updateOrCreate(
                ['company_id' => $companyId, 'item_key' => $item],
                ['is_visible' => $data['is_visible'], 'updated_by' => $request->user()->id],
            );
            $this->audit->record($request, $request->user(), $companyId, 'navigation_visibility_updated', 'platform', $preference, $oldValues, [
                'item_key' => $item, 'is_visible' => $preference->is_visible,
            ]);
        });

        return $this->index($request);
    }

    public function reset(Request $request, string $item): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'platform.settings.manage');
        abort_unless($this->navigation->hasItem($item), 404);

        DB::transaction(function () use ($request, $companyId, $item): void {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $preference = CompanyNavigationPreference::query()->where('company_id', $companyId)->where('item_key', $item)->first();
            if ($preference === null) {
                return;
            }
            $oldValues = ['item_key' => $item, 'is_visible' => $preference->is_visible];
            $preference->delete();
            $this->audit->record($request, $request->user(), $companyId, 'navigation_visibility_reset', 'platform', $preference, $oldValues, null);
        });

        return $this->index($request);
    }
}
