<?php

namespace App\Services\Platform;

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
        'fbr.invoicing' => ['FBR Invoicing', 'invoicing', 'pakistan_fbr.view', 'Finance'],
        'fbr.configuration' => ['FBR Configuration', 'invoicing', 'fbr.configuration.view', 'Finance'],
        'fbr.migrations' => ['FBR Migration Administration', 'invoicing', 'migration.view', 'Finance'],
        'accounting.invoices' => ['Accounting Invoices', 'invoicing', 'accounting.view', 'Finance'],
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
    ];

    public function __construct(private readonly EntitlementService $entitlements) {}

    public function hasItem(string $key): bool
    {
        return array_key_exists($key, self::ITEMS);
    }

    /** @param array<int, string> $permissions @return array{catalog: array<int, array<string, mixed>>, items: array<int, array<string, mixed>>, visible_keys: array<int, string>} */
    public function resolve(string $companyId, array $permissions): array
    {
        $modules = PlatformModule::query()->orderBy('name')->get()->keyBy('key');
        $entitledKeys = $this->entitlements->enabledModules($companyId);
        $preferences = CompanyNavigationPreference::query()->where('company_id', $companyId)->get()->keyBy('item_key');
        $allPermissions = in_array('*', $permissions, true);
        $items = [];

        foreach (self::ITEMS as $key => [$label, $moduleKey, $permission, $group]) {
            $module = $modules->get($moduleKey);
            $active = $module !== null && $module->is_active;
            $entitled = in_array($moduleKey, $entitledKeys, true);
            $authorized = $allPermissions || in_array($permission, $permissions, true);
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
                'module_key' => $moduleKey, 'required_permission' => $permission,
                'platform_active' => $active, 'entitled' => $entitled, 'authorized' => $authorized,
                'visibility_override' => $visibilityOverride,
                'presentation_visible' => $presentationVisible, 'effective_visible' => $reason === null,
                'unavailable_reason' => $reason,
            ];
        }

        return [
            'catalog' => $modules->values()->map(fn (PlatformModule $module): array => [
                'key' => $module->key, 'name' => $module->name, 'is_active' => $module->is_active,
                'entitled' => in_array($module->key, $entitledKeys, true),
            ])->all(),
            'items' => $items,
            'visible_keys' => array_values(array_column(array_filter($items, fn (array $item): bool => $item['effective_visible']), 'key')),
        ];
    }
}
