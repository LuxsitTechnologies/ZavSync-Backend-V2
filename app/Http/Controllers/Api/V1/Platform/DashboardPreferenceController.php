<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Services\AuditService;
use App\Services\Platform\CompanySettingsService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardPreferenceController extends Controller
{
    /** @var array<string, array{label: string, module: string, permission: string}> */
    private const WIDGETS = [
        'revenue' => ['label' => 'Revenue (MTD)', 'module' => 'invoicing', 'permission' => 'accounting.view'],
        'outstanding' => ['label' => 'Outstanding', 'module' => 'receivables', 'permission' => 'accounting.view'],
        'collected' => ['label' => 'Collected (MTD)', 'module' => 'receivables', 'permission' => 'accounting.view'],
        'overdue' => ['label' => 'Overdue invoices', 'module' => 'receivables', 'permission' => 'accounting.view'],
        'invoiced_collected' => ['label' => 'Invoiced vs collected', 'module' => 'invoicing', 'permission' => 'accounting.view'],
        'pending_attention' => ['label' => 'Pending attention', 'module' => 'invoicing', 'permission' => 'accounting.view'],
        'recent_invoices' => ['label' => 'Recent invoices', 'module' => 'invoicing', 'permission' => 'accounting.view'],
    ];

    public function __construct(
        private readonly PlatformAccessService $access,
        private readonly EntitlementService $entitlements,
        private readonly CompanySettingsService $settings,
        private readonly AuditService $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $permissions = $this->access->permissionNames($request->user(), $companyId);
        if (! in_array('*', $permissions, true) && ! in_array('accounting.view', $permissions, true)
            && ! in_array('platform.settings.view', $permissions, true) && ! in_array('platform.settings.manage', $permissions, true)) {
            throw new PlatformException('PERMISSION_DENIED', 'You cannot view dashboard preferences.', 403);
        }

        return response()->json($this->response($companyId, $permissions));
    }

    public function update(Request $request): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'platform.settings.manage');
        $data = $request->validate([
            'layout_version' => ['required', 'integer', 'min:1'],
            'columns' => ['required', 'integer', 'between:1,4'],
            'widgets' => ['required', 'array', 'size:'.count(self::WIDGETS)],
            'widgets.*' => ['required', 'array:key,visible'],
            'widgets.*.key' => ['required', 'string', Rule::in(array_keys(self::WIDGETS)), 'distinct:strict'],
            'widgets.*.visible' => ['required', 'boolean'],
        ]);
        $keys = array_column($data['widgets'], 'key');
        if (count(array_unique($keys)) !== count(self::WIDGETS)) {
            throw ValidationException::withMessages(['widgets' => 'Provide each supported dashboard widget exactly once.']);
        }
        DB::transaction(function () use ($request, $companyId, $data): void {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);
            $settings = $this->settings->get($company);
            $this->assertVersion($settings, $data['layout_version']);
            $before = ['widgets' => $settings->dashboard_layout, 'columns' => $settings->dashboard_layout_columns];
            $settings->update([
                'dashboard_layout' => $data['widgets'], 'dashboard_layout_columns' => $data['columns'],
                'dashboard_layout_version' => $this->nextVersion($settings), 'updated_by' => $request->user()->id,
            ]);
            $this->audit->record($request, $request->user(), $companyId, 'dashboard_layout_updated', 'platform', $settings, $before,
                ['widgets' => $data['widgets'], 'columns' => $data['columns']]);
        });

        return $this->show($request);
    }

    public function reset(Request $request): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'platform.settings.manage');
        $data = $request->validate(['layout_version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $companyId, $data): void {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);
            $settings = $this->settings->get($company);
            $this->assertVersion($settings, $data['layout_version']);
            if ($settings->dashboard_layout === null && $settings->dashboard_layout_columns === 4) {
                return;
            }
            $before = ['widgets' => $settings->dashboard_layout, 'columns' => $settings->dashboard_layout_columns];
            $settings->update([
                'dashboard_layout' => null, 'dashboard_layout_columns' => 4,
                'dashboard_layout_version' => $this->nextVersion($settings), 'updated_by' => $request->user()->id,
            ]);
            $this->audit->record($request, $request->user(), $companyId, 'dashboard_layout_reset', 'platform', $settings, $before, null);
        });

        return $this->show($request);
    }

    /** @param array<int, string> $permissions @return array<string, mixed> */
    private function response(string $companyId, array $permissions): array
    {
        $settings = $this->settings->get(Company::query()->findOrFail($companyId));
        $layout = $settings->dashboard_layout ?? array_map(
            fn (string $key): array => ['key' => $key, 'visible' => true], array_keys(self::WIDGETS),
        );
        $entitled = $this->entitlements->enabledModules($companyId);
        $allPermissions = in_array('*', $permissions, true);
        $catalog = [];
        foreach (self::WIDGETS as $key => $widget) {
            $catalog[] = [
                'key' => $key, ...$widget,
                'authorized' => $allPermissions || in_array($widget['permission'], $permissions, true),
                'entitled' => in_array($widget['module'], $entitled, true),
            ];
        }
        $availability = collect($catalog)->keyBy('key');
        $widgets = array_map(function (array $item) use ($availability): array {
            $capability = $availability->get($item['key']);

            return [
                'key' => $item['key'], 'visible' => (bool) $item['visible'],
                'effective_visible' => (bool) $item['visible'] && $capability['authorized'] && $capability['entitled'],
            ];
        }, $layout);

        return [
            'catalog' => $catalog, 'widgets' => $widgets,
            'columns' => $settings->dashboard_layout_columns,
            'layout_version' => $settings->dashboard_layout_version,
        ];
    }

    private function assertVersion(CompanySetting $settings, int $version): void
    {
        if ($settings->dashboard_layout_version !== $version) {
            throw new PlatformException('DASHBOARD_LAYOUT_VERSION_STALE', 'The dashboard layout changed; reload it before saving.', 409);
        }
    }

    private function nextVersion(CompanySetting $settings): int
    {
        if ($settings->dashboard_layout_version >= PHP_INT_MAX) {
            throw new PlatformException('DASHBOARD_LAYOUT_VERSION_EXHAUSTED', 'The dashboard layout version is exhausted.', 409);
        }

        return $settings->dashboard_layout_version + 1;
    }
}
