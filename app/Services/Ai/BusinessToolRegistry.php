<?php

namespace App\Services\Ai;

use App\Contracts\AiToolRegistry;
use App\Exceptions\PlatformException;
use App\Models\AiToolRun;
use App\Models\CrmActivity;
use App\Models\CrmDeal;
use App\Models\User;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\FinancialReportService;
use App\Services\Banking\BankingReportingService;
use App\Services\Inventory\InventoryReportingService;
use App\Services\Outreach\OutreachReportService;
use App\Services\Payroll\PayrollReportingService;
use App\Services\Platform\EntitlementService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class BusinessToolRegistry implements AiToolRegistry
{
    /** @var array<string, array{permission:string,module:string,description:string,schema:array<string, mixed>}> */
    private const TOOLS = [
        'accounting.trial_balance' => ['permission' => 'accounting.view', 'module' => 'accounting', 'description' => 'Read the authoritative posted trial balance.', 'schema' => ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']],
        'receivables.aging' => ['permission' => 'accounting.view', 'module' => 'receivables', 'description' => 'Read customer receivable aging.', 'schema' => ['customer_id' => 'nullable|uuid', 'as_of' => 'nullable|date_format:Y-m-d']],
        'payables.aging' => ['permission' => 'payables.view', 'module' => 'payables', 'description' => 'Read supplier payable aging.', 'schema' => ['supplier_id' => 'nullable|uuid', 'as_of' => 'nullable|date_format:Y-m-d']],
        'inventory.low_stock' => ['permission' => 'inventory.view', 'module' => 'inventory', 'description' => 'Read current low-stock items.', 'schema' => []],
        'banking.cash_position' => ['permission' => 'banking.cashflow', 'module' => 'banking', 'description' => 'Read current cash and bank position.', 'schema' => ['as_of' => 'nullable|date_format:Y-m-d']],
        'payroll.summary' => ['permission' => 'payroll.reports', 'module' => 'payroll', 'description' => 'Read payroll summary totals.', 'schema' => ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']],
        'crm.pipeline' => ['permission' => 'crm.reports.view', 'module' => 'crm', 'description' => 'Read open CRM pipeline and overdue follow-up totals.', 'schema' => []],
        'outreach.performance' => ['permission' => 'outreach.reports.view', 'module' => 'outreach', 'description' => 'Read outreach delivery and engagement reporting.', 'schema' => []],
    ];

    public function __construct(
        private readonly FinancialReportService $financialReports,
        private readonly AccountsReceivableService $receivables,
        private readonly AccountsPayableService $payables,
        private readonly InventoryReportingService $inventory,
        private readonly BankingReportingService $banking,
        private readonly PayrollReportingService $payroll,
        private readonly OutreachReportService $outreach,
        private readonly EntitlementService $entitlements,
    ) {}

    public function definitions(User $user, string $companyId): array
    {
        if (! $user->hasCompanyPermission($companyId, 'ai.tools.use')) {
            return [];
        }
        $modules = $this->entitlements->enabledModules($companyId);

        return collect(self::TOOLS)->filter(fn (array $tool) => in_array($tool['module'], $modules, true) && $user->hasCompanyPermission($companyId, $tool['permission']))
            ->map(fn (array $tool, string $name): array => ['name' => $name, 'description' => $tool['description'], 'input_schema' => $tool['schema']])
            ->values()->all();
    }

    public function execute(User $user, string $companyId, string $toolName, array $arguments, ?string $messageId = null): array
    {
        if (! $user->hasCompanyPermission($companyId, 'ai.tools.use')) {
            throw new PlatformException('AI_TOOL_PERMISSION_DENIED', 'You do not have permission to use AI business-data tools.', 403);
        }
        $tool = self::TOOLS[$toolName] ?? null;
        if ($tool === null) {
            throw new PlatformException('AI_TOOL_NOT_ALLOWED', 'The requested AI tool is not allowlisted.', 422);
        }
        if (! in_array($tool['module'], $this->entitlements->enabledModules($companyId), true)) {
            throw new PlatformException('MODULE_NOT_ENTITLED', 'The requested tool module is not enabled for this company.', 403);
        }
        if (! $user->hasCompanyPermission($companyId, $tool['permission'])) {
            throw new PlatformException('AI_TOOL_PERMISSION_DENIED', 'You do not have permission to use this business-data tool.', 403);
        }
        $unexpectedArguments = array_diff(array_keys($arguments), array_keys($tool['schema']));
        if ($unexpectedArguments !== []) {
            throw ValidationException::withMessages(['arguments' => 'The AI tool supplied unsupported arguments.']);
        }
        $rules = $tool['schema'];
        if ($toolName === 'receivables.aging') {
            $rules['customer_id'] = ['nullable', 'uuid', Rule::exists('customers', 'id')->where('company_id', $companyId)];
        }
        if ($toolName === 'payables.aging') {
            $rules['supplier_id'] = ['nullable', 'uuid', Rule::exists('suppliers', 'id')->where('company_id', $companyId)];
        }
        $validated = Validator::make($arguments, $rules)->validate();
        $run = AiToolRun::query()->create(['company_id' => $companyId, 'ai_message_id' => $messageId, 'user_id' => $user->id, 'tool_name' => $toolName, 'required_permission' => $tool['permission'], 'status' => 'PROCESSING', 'input' => $validated, 'started_at' => now()]);

        try {
            $output = match ($toolName) {
                'accounting.trial_balance' => $this->financialReports->trialBalance($companyId, $validated['from'] ?? null, $validated['to'] ?? null),
                'receivables.aging' => $this->receivables->aging($companyId, $validated['customer_id'] ?? null, $validated['as_of'] ?? now()->toDateString()),
                'payables.aging' => $this->payables->aging($companyId, $validated['supplier_id'] ?? null, $validated['as_of'] ?? now()->toDateString()),
                'inventory.low_stock' => ['items' => $this->inventory->lowStock($companyId)->toArray()],
                'banking.cash_position' => $this->banking->currentCash($companyId, $validated['as_of'] ?? null),
                'payroll.summary' => $this->payroll->summary($companyId, $validated),
                'crm.pipeline' => $this->crmPipeline($companyId),
                'outreach.performance' => $this->outreach->summary($companyId),
            };
            $run->update(['status' => 'COMPLETED', 'output' => $output, 'completed_at' => now()]);

            return ['tool' => $toolName, 'data' => $output];
        } catch (Throwable $exception) {
            $run->update(['status' => 'FAILED', 'error_code' => $exception instanceof PlatformException ? $exception->errorCode : 'AI_TOOL_FAILED', 'error_message' => $exception instanceof PlatformException ? $exception->getMessage() : 'The business-data tool could not be completed.', 'completed_at' => now()]);
            throw $exception;
        }
    }

    /** @return array<string, int> */
    private function crmPipeline(string $companyId): array
    {
        $deals = CrmDeal::query()->where('company_id', $companyId)->where('status', 'OPEN')->get(['amount', 'probability_bps']);

        return [
            'open_deals' => $deals->count(),
            'open_amount_minor' => (int) $deals->sum('amount'),
            'weighted_amount_minor' => (int) $deals->sum(fn (CrmDeal $deal): int => intdiv($deal->amount * $deal->probability_bps, 10_000)),
            'overdue_follow_ups' => CrmActivity::query()->where('company_id', $companyId)->where('status', 'PENDING')->where('due_at', '<', now())->count(),
        ];
    }
}
