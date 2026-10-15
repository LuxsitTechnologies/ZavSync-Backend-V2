<?php

namespace App\Services\Platform;

use App\Models\Company;
use App\Models\CompanyNavigationPreference;
use App\Models\PlatformModule;

class NavigationVisibilityService
{
    /**
     * Stable presentation identities; commercial modules remain in PlatformModule.
     * The final field is a non-executable grouping hint, never a Vue route.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const ITEMS = [
        'hrm.employees' => ['Employees', 'payroll', 'payroll.view', 'People'],
        'hrm.attendance' => ['Attendance', 'payroll', 'attendance.view', 'People'],
        'hrm.leave' => ['Leave', 'payroll', 'leave.view', 'People'],
        'fbr.invoicing' => ['FBR Invoice', 'fbr', 'pakistan_fbr.view', 'Finance'],
        'fbr.configuration' => ['FBR Configuration', 'fbr', 'fbr.configuration.view', 'Finance'],
        'fbr.portal_settings' => ['Portal Settings', 'fbr', 'portal.settings.view', 'Finance'],
        'fbr.migrations' => ['FBR Migration Administration', 'fbr', 'migration.view', 'Finance'],
        'accounting.invoices' => ['Accounting Invoices', 'invoicing', 'accounting.view', 'Finance'],
        'accounting.fbr_status' => ['Accounting Invoice FBR Status', 'invoicing', 'accounting.view', 'Finance'],
        'accounting.chart' => ['Chart of Accounts', 'accounting', 'accounting.view', 'Finance'],
        'accounting.ledger' => ['General Ledger', 'accounting', 'accounting.view', 'Finance'],
        'accounting.journals' => ['Journals', 'accounting', 'accounting.view', 'Finance'],
        'accounting.periods' => ['Periods', 'accounting', 'accounting.view', 'Finance'],
        'accounting.setup' => ['Accounting Setup', 'accounting', 'accounting.view', 'Finance'],
        'accounting.reports' => ['Financial Reports', 'accounting', 'accounting.view', 'Finance'],
        'receivables.customers' => ['Customers', 'receivables', 'accounting.view', 'Finance'],
        'receivables.register' => ['Receivables', 'receivables', 'accounting.view', 'Finance'],
        'payables.register' => ['Payables', 'payables', 'payables.view', 'Finance'],
        'inventory.ledger' => ['Inventory Ledger', 'inventory', 'inventory.view', 'Finance'],
        'banking.cashflow' => ['Cash Flow', 'banking', 'banking.cashflow', 'Finance'],
        'budgeting.workspace' => ['Budgeting', 'budgeting', 'budget.view', 'Finance'],
        'budgeting.year_end' => ['Year-End Close', 'budgeting', 'accounting.close.view', 'Finance'],
        'payroll.dashboard' => ['Payroll Dashboard', 'payroll', 'payroll.view', 'Finance'],
        'payroll.batches' => ['Payroll Batches', 'payroll', 'payroll.view', 'Finance'],
        'payroll.runs' => ['Payroll Runs', 'payroll', 'payroll.view', 'Finance'],
        'payroll.allowances' => ['Allowances', 'payroll', 'payroll.view', 'Finance'],
        'payroll.deductions' => ['Deductions', 'payroll', 'payroll.view', 'Finance'],
        'payroll.posting' => ['Payroll Accounting Posting', 'payroll', 'payroll.view', 'Finance'],
        'inventory.workspace' => ['Inventory', 'inventory', 'inventory.view', 'Operations'],
        'procurement.purchases' => ['Purchases', 'procurement', 'purchase_orders.view', 'Operations'],
        'crm.dashboard' => ['CRM Overview', 'crm', 'crm.view', 'Operations'],
        'crm.companies' => ['CRM Companies', 'crm', 'crm.view', 'Operations'],
        'crm.contacts' => ['CRM Contacts', 'crm', 'crm.view', 'Operations'],
        'crm.leads' => ['CRM Leads', 'crm', 'crm.view', 'Operations'],
        'crm.pipeline' => ['CRM Pipeline', 'crm', 'crm.view', 'Operations'],
        'crm.deals' => ['CRM Deals', 'crm', 'crm.view', 'Operations'],
        'crm.activities' => ['CRM Activities', 'crm', 'crm.view', 'Operations'],
        'crm.tasks' => ['CRM Tasks', 'crm', 'crm.view', 'Operations'],
        'crm.capture' => ['Lead Capture', 'crm', 'crm.import', 'Operations'],
        'crm.scoring' => ['Lead Scoring', 'crm', 'crm.view', 'Operations'],
        'ai.copilot' => ['Ask ZavSync', 'ai', 'ai.copilot.use', 'Intelligence'],
        'ai.priorities' => ['Priorities', 'ai', 'intelligence.view', 'Intelligence'],
        'ai.briefing' => ['Management Briefing', 'ai', 'intelligence.briefings.view', 'Intelligence'],
        'ai.analytics' => ['Predictive Analytics', 'ai', 'intelligence.anomalies.view', 'Intelligence'],
        'ai.operations' => ['AI Operations', 'ai', 'intelligence.observability.view', 'Intelligence'],
        'ai.actions' => ['Action Review', 'ai', 'ai.actions.review', 'Intelligence'],
        'ai.calendar' => ['Smart Agenda', 'ai', 'intelligence.calendar.manage', 'Intelligence'],
        'ai.meetings' => ['Meeting Assistant', 'ai', 'intelligence.calendar.manage', 'Intelligence'],
        'ai.execution' => ['Execution Copilot', 'ai', 'ai.actions.review', 'Intelligence'],
        'ai.knowledge_documents' => ['Knowledge Documents', 'ai', 'ai.knowledge.view', 'Intelligence'],
        'ai.knowledge_chat' => ['Knowledge Chat', 'ai', 'ai.copilot.use', 'Intelligence'],
        'ai.knowledge_governance' => ['Knowledge Governance', 'ai', 'ai.providers.view', 'Intelligence'],
        'outreach.providers' => ['Providers & Senders', 'outreach', 'outreach.providers.manage', 'Intelligence'],
        'outreach.templates' => ['Templates & Composer', 'outreach', 'outreach.templates.manage', 'Intelligence'],
        'outreach.sequences' => ['Sequences & Enrollments', 'outreach', 'outreach.sequences.manage', 'Intelligence'],
        'outreach.tracking' => ['Dashboard & Tracking', 'outreach', 'outreach.reports.view', 'Intelligence'],
        'employee.tasks' => ['My Tasks', 'payroll', 'employee.tasks.view', 'People'],
        'employee.tickets' => ['My Tickets', 'payroll', 'employee.tickets.view', 'People'],
        'hrm.tasks' => ['Tasks', 'payroll', 'tasks.view', 'People'],
        'hrm.tickets' => ['Tickets', 'payroll', 'tickets.view', 'People'],
        'employee.documents' => ['My Documents', 'payroll', 'employee.documents.view', 'People'],
        'hrm.employee_documents' => ['Employee Documents', 'payroll', 'employee.documents.admin.view', 'People'],
        'employee.profile' => ['My Profile', 'payroll', 'employee.self.view', 'People'],
        'employee.announcements' => ['Announcements', 'payroll', 'employee.announcements.view', 'People'],
        'hrm.announcements' => ['Announcements Administration', 'payroll', 'announcements.view', 'People'],
        'employee.directory' => ['Colleagues', 'payroll', 'employee.directory.view', 'People'],
        'employee.teams' => ['My Teams', 'payroll', 'employee.teams.view', 'People'],
        'hrm.teams' => ['Teams', 'payroll', 'teams.view', 'People'],
        'employee.assets' => ['Equipment Requests', 'payroll', 'employee.assets.view', 'People'],
        'hrm.asset_requests' => ['Equipment Requests Administration', 'payroll', 'assets.view', 'People'],
        'employee.expenses' => ['My Expenses', 'payroll', 'employee.expenses.view', 'People'],
        'hrm.expenses' => ['Expense Claims', 'payroll', 'expenses.view', 'People'],
        'hrm.expense_categories' => ['Expense Categories', 'payroll', 'expenses.categories.manage', 'People'],
        'employee.schedule' => ['My Schedule', 'payroll', 'employee.schedule.view', 'People'],
        'employee.shift_swaps' => ['Shift Swaps', 'payroll', 'employee.schedule.view', 'People'],
        'hrm.shifts' => ['Shifts', 'payroll', 'schedules.view', 'People'],
        'hrm.rotas' => ['Rotas', 'payroll', 'schedules.view', 'People'],
        'hrm.shift_swaps' => ['Shift Swap Decisions', 'payroll', 'schedules.view', 'People'],
    ];

    /** @var array<string, string> */
    private const PARENT_LABELS = [
        'people.hrm' => 'HRM',
        'finance.fbr' => 'FBR Invoice',
        'finance.accounting' => 'Accounting',
        'finance.payroll' => 'Payroll',
        'operations.crm' => 'CRM',
        'intelligence.ai' => 'ZavSync AI',
        'intelligence.knowledge' => 'Knowledge',
        'intelligence.outreach' => 'Outreach',
    ];

    public function __construct(private readonly EntitlementService $entitlements) {}

    public function hasItem(string $key): bool
    {
        return array_key_exists($key, self::ITEMS);
    }

    /** @param array<int, string> $permissions @return array{catalog: array<int, array<string, mixed>>, items: array<int, array<string, mixed>>, parents: array<int, array<string, mixed>>, visible_keys: array<int, string>} */
    public function resolve(string $companyId, array $permissions, ?int $userId = null): array
    {
        $company = Company::query()->findOrFail($companyId);
        $commerciallyManaged = (bool) $company->billing_policy_applies;
        $modules = PlatformModule::query()->orderBy('name')->get()->keyBy('key');
        $entitledKeys = $this->entitlements->enabledModules($companyId);
        $preferences = CompanyNavigationPreference::query()->where('company_id', $companyId)->get()->keyBy('item_key');
        $allPermissions = in_array('*', $permissions, true);
        $items = [];

        foreach (self::ITEMS as $key => [$label, $moduleKey, $permission, $group]) {
            if (str_starts_with($key, 'fbr.') && ! $commerciallyManaged) {
                $moduleKey = 'invoicing';
            }
            $module = $modules->get($moduleKey);
            $active = $module !== null && $module->is_active;
            $entitled = in_array($moduleKey, $entitledKeys, true);
            $authorized = $allPermissions || in_array($permission, $permissions, true)
                || ($key === 'fbr.portal_settings' && $userId !== null && (int) $company->created_by === $userId);
            $visibilityOverride = $preferences->get($key)?->is_visible;
            $presentationVisible = $visibilityOverride !== false;
            $reason = match (true) {
                ! $active => 'PLATFORM_INACTIVE',
                ! $entitled => 'NOT_ENTITLED',
                ! $authorized => 'NOT_AUTHORIZED',
                ! $presentationVisible => 'HIDDEN_BY_COMPANY',
                default => null,
            };
            $items[] = [
                'key' => $key, 'label' => $label, 'group' => $group, 'order' => count($items),
                'parent_key' => $this->parentKey($key),
                'module_key' => $moduleKey, 'required_permission' => $permission,
                'platform_active' => $active, 'entitled' => $entitled, 'authorized' => $authorized,
                'visibility_override' => $visibilityOverride,
                'presentation_visible' => $presentationVisible, 'effective_visible' => $reason === null,
                'unavailable_reason' => $reason,
            ];
        }

        $parents = [];
        foreach (self::PARENT_LABELS as $parentKey => $parentLabel) {
            $children = array_values(array_filter($items, fn (array $item): bool => $item['parent_key'] === $parentKey));
            $parents[] = [
                'key' => $parentKey, 'label' => $parentLabel,
                'child_keys' => array_column($children, 'key'),
                'visible_child_keys' => array_column(array_filter($children, fn (array $item): bool => $item['effective_visible']), 'key'),
                'effective_visible' => count(array_filter($children, fn (array $item): bool => $item['effective_visible'])) > 0,
            ];
        }

        return [
            'catalog' => $modules->values()->map(fn (PlatformModule $module): array => [
                'key' => $module->key, 'name' => $module->name, 'is_active' => $module->is_active,
                'entitled' => in_array($module->key, $entitledKeys, true),
            ])->all(),
            'items' => $items,
            'parents' => $parents,
            'visible_keys' => array_values(array_column(array_filter($items, fn (array $item): bool => $item['effective_visible']), 'key')),
        ];
    }

    private function parentKey(string $key): ?string
    {
        return match (true) {
            str_starts_with($key, 'hrm.') => 'people.hrm',
            str_starts_with($key, 'fbr.') => 'finance.fbr',
            str_starts_with($key, 'accounting.'), str_starts_with($key, 'receivables.'),
            str_starts_with($key, 'payables.'), str_starts_with($key, 'banking.'),
            str_starts_with($key, 'budgeting.'), $key === 'inventory.ledger' => 'finance.accounting',
            str_starts_with($key, 'payroll.') => 'finance.payroll',
            str_starts_with($key, 'crm.') => 'operations.crm',
            str_starts_with($key, 'outreach.') => 'intelligence.outreach',
            str_starts_with($key, 'ai.knowledge_') => 'intelligence.knowledge',
            str_starts_with($key, 'ai.') => 'intelligence.ai',
            default => null,
        };
    }
}
