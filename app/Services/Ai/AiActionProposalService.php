<?php

namespace App\Services\Ai;

use App\Exceptions\PlatformException;
use App\Models\AiActionExecution;
use App\Models\AiActionProposal;
use App\Models\User;
use App\Services\Accounting\InvoiceCalculationService;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\PurchaseCalculationService;
use App\Services\Accounting\PurchaseOrderService;
use App\Services\AuditService;
use App\Services\Crm\CrmActivityService;
use App\Services\Crm\CrmRecordUpdateService;
use App\Services\Outreach\SequenceService;
use App\Services\Platform\EntitlementService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiActionProposalService
{
    /** @var array<string, array{permission:string,module:string}> */
    private const ACTIONS = [
        'CRM_ACTIVITY_DRAFT' => ['permission' => 'crm.activities.manage', 'module' => 'crm'],
        'CRM_LEAD_UPDATE' => ['permission' => 'crm.leads.manage', 'module' => 'crm'],
        'CRM_DEAL_UPDATE' => ['permission' => 'crm.deals.manage', 'module' => 'crm'],
        'OUTREACH_SEQUENCE_DRAFT' => ['permission' => 'outreach.sequences.manage', 'module' => 'outreach'],
        'INVOICE_DRAFT' => ['permission' => 'accounting.create', 'module' => 'invoicing'],
        'JOURNAL_DRAFT' => ['permission' => 'accounting.post', 'module' => 'accounting'],
        'PURCHASE_ORDER_DRAFT' => ['permission' => 'purchase_orders.create', 'module' => 'procurement'],
    ];

    public function __construct(
        private readonly InvoiceCalculationService $invoiceCalculation,
        private readonly PurchaseCalculationService $purchaseCalculation,
        private readonly InvoiceService $invoices,
        private readonly JournalPostingService $journals,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly SequenceService $sequences,
        private readonly CrmActivityService $crmActivities,
        private readonly CrmRecordUpdateService $crmRecords,
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $payload */
    public function propose(Request $request, User $user, string $companyId, string $actionType, array $payload, string $idempotencyKey, ?string $conversationId = null, ?string $messageId = null): AiActionProposal
    {
        if (! $user->hasCompanyPermission($companyId, 'ai.actions.propose')) {
            throw new PlatformException('AI_ACTION_PERMISSION_DENIED', 'You do not have permission to propose AI actions.', 403);
        }
        $actionType = mb_strtoupper($actionType);
        $definition = $this->authorizeAction($user, $companyId, $actionType);
        $validated = $this->validatePayload($companyId, $actionType, $payload);
        $checksum = $this->checksum($validated);
        $existing = AiActionProposal::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            if ($existing->action_type !== $actionType || ! hash_equals($existing->payload_checksum, $checksum)) {
                throw new PlatformException('IDEMPOTENCY_KEY_REUSED', 'The idempotency key has already been used for a different AI action proposal.', 409);
            }

            return $existing;
        }
        $proposal = AiActionProposal::query()->create([
            'company_id' => $companyId,
            'ai_conversation_id' => $conversationId,
            'ai_message_id' => $messageId,
            'created_by' => $user->id,
            'action_type' => $actionType,
            'status' => 'PENDING',
            'payload' => $validated,
            'impact_preview' => $this->impact($actionType, $validated),
            'required_permission' => $definition['permission'],
            'payload_checksum' => $checksum,
            'idempotency_key' => $idempotencyKey,
            'expires_at' => now()->addDay(),
        ]);
        $this->audit->record($request, $user, $companyId, 'ai_action_proposed', 'ai', $proposal, null, $this->auditValues($proposal));

        return $proposal;
    }

    public function approve(Request $request, User $user, string $companyId, AiActionProposal $proposal): AiActionProposal
    {
        if (! $user->hasCompanyPermission($companyId, 'ai.actions.approve')) {
            throw new PlatformException('AI_ACTION_PERMISSION_DENIED', 'You do not have permission to approve AI actions.', 403);
        }
        $this->expireIfPast($companyId, $proposal);
        $proposal = $this->lockProposal($companyId, $proposal, function (AiActionProposal $locked) use ($user): void {
            $this->authorizeAction($user, $locked->company_id, $locked->action_type);
            if (in_array($locked->status, ['APPROVED', 'EXECUTED'], true)) {
                return;
            }
            $this->assertPendingAndCurrent($locked);
            $validated = $this->validatePayload($locked->company_id, $locked->action_type, $locked->payload);
            if (! hash_equals($locked->payload_checksum, $this->checksum($validated))) {
                throw new PlatformException('AI_ACTION_PAYLOAD_CHANGED', 'The proposal payload no longer matches its integrity checksum.', 409);
            }
            $locked->update(['status' => 'APPROVED', 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'rejection_reason' => null]);
        });
        $this->audit->record($request, $user, $companyId, 'ai_action_approved', 'ai', $proposal, null, $this->auditValues($proposal));

        return $proposal;
    }

    public function reject(Request $request, User $user, string $companyId, AiActionProposal $proposal, string $reason): AiActionProposal
    {
        if (! $user->hasCompanyPermission($companyId, 'ai.actions.approve')) {
            throw new PlatformException('AI_ACTION_PERMISSION_DENIED', 'You do not have permission to review AI actions.', 403);
        }
        $this->expireIfPast($companyId, $proposal);
        $proposal = $this->lockProposal($companyId, $proposal, function (AiActionProposal $locked) use ($user, $reason): void {
            $this->assertPendingAndCurrent($locked);
            $locked->update(['status' => 'REJECTED', 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'rejection_reason' => $reason]);
        });
        $this->audit->record($request, $user, $companyId, 'ai_action_rejected', 'ai', $proposal, null, $this->auditValues($proposal));

        return $proposal;
    }

    public function execute(Request $request, User $user, string $companyId, AiActionProposal $proposal, string $idempotencyKey): AiActionExecution
    {
        if (! $user->hasCompanyPermission($companyId, 'ai.actions.execute')) {
            throw new PlatformException('AI_ACTION_PERMISSION_DENIED', 'You do not have permission to execute AI actions.', 403);
        }
        $this->expireIfPast($companyId, $proposal);
        [$locked, $execution] = DB::transaction(function () use ($user, $companyId, $proposal, $idempotencyKey): array {
            $locked = AiActionProposal::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($proposal->id);
            $this->authorizeAction($user, $companyId, $locked->action_type);
            if ($locked->expires_at->isPast()) {
                $locked->update(['status' => 'EXPIRED']);
                throw new PlatformException('AI_ACTION_EXPIRED', 'This AI action proposal has expired.', 409);
            }
            if ($locked->status === 'EXECUTED') {
                return [$locked, $locked->execution()->firstOrFail()];
            }
            if ($locked->status !== 'APPROVED') {
                throw new PlatformException('AI_ACTION_NOT_APPROVED', 'The AI action proposal must be explicitly approved before execution.', 409);
            }
            $validated = $this->validatePayload($companyId, $locked->action_type, $locked->payload);
            if (! hash_equals($locked->payload_checksum, $this->checksum($validated))) {
                throw new PlatformException('AI_ACTION_PAYLOAD_CHANGED', 'The approved proposal payload no longer matches its integrity checksum.', 409);
            }
            $execution = $locked->execution()->first();
            if ($execution !== null && ! hash_equals($execution->idempotency_key, $idempotencyKey)) {
                throw new PlatformException('IDEMPOTENCY_KEY_REUSED', 'This proposal already has an execution with a different idempotency key.', 409);
            }
            if ($execution?->status === 'COMPLETED') {
                return [$locked, $execution];
            }
            if ($execution?->status === 'PROCESSING') {
                throw new PlatformException('AI_ACTION_EXECUTION_IN_PROGRESS', 'This proposal is already being executed.', 409);
            }
            $execution ??= AiActionExecution::query()->create(['company_id' => $companyId, 'ai_action_proposal_id' => $locked->id, 'executed_by' => $user->id, 'status' => 'PENDING', 'idempotency_key' => $idempotencyKey]);
            $execution->update(['status' => 'PROCESSING', 'executed_by' => $user->id, 'started_at' => now(), 'completed_at' => null, 'error_code' => null, 'error_message' => null]);

            return [$locked, $execution];
        });
        if ($execution->status === 'COMPLETED') {
            return $execution;
        }

        try {
            $result = $this->perform($locked, $user);
            DB::transaction(function () use ($locked, $execution, $result): void {
                $execution->update(['status' => 'COMPLETED', 'result_type' => $result->getMorphClass(), 'result_id' => (string) $result->getKey(), 'result_summary' => 'Approved draft action executed through its domain service.', 'completed_at' => now()]);
                $locked->update(['status' => 'EXECUTED', 'executed_at' => now()]);
            });
            $this->audit->record($request, $user, $companyId, 'ai_action_executed', 'ai', $execution, null, $execution->fresh()->toArray());

            return $execution->fresh();
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof PlatformException ? $exception->errorCode : 'AI_ACTION_EXECUTION_FAILED';
            $errorMessage = $exception instanceof PlatformException ? $exception->getMessage() : 'The approved action could not be completed.';
            $execution->update(['status' => 'FAILED', 'error_code' => $errorCode, 'error_message' => $errorMessage, 'completed_at' => now()]);
            $this->audit->record($request, $user, $companyId, 'ai_action_execution_failed', 'ai', $execution, null, ['id' => $execution->id, 'proposal_id' => $locked->id, 'error_code' => $errorCode]);
            throw $exception;
        }
    }

    /** @return array{permission:string,module:string} */
    private function authorizeAction(User $user, string $companyId, string $actionType): array
    {
        $definition = self::ACTIONS[$actionType] ?? null;
        if ($definition === null) {
            throw new PlatformException('AI_ACTION_NOT_ALLOWED', 'This action is not available through the AI approval workflow.', 422);
        }
        if (! in_array($definition['module'], $this->entitlements->enabledModules($companyId), true)) {
            throw new PlatformException('MODULE_NOT_ENTITLED', 'The target module is not enabled for this company.', 403);
        }
        if (! $user->hasCompanyPermission($companyId, $definition['permission'])) {
            throw new PlatformException('AI_ACTION_PERMISSION_DENIED', 'You do not have permission for the proposed domain action.', 403);
        }

        return $definition;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function validatePayload(string $companyId, string $actionType, array $payload): array
    {
        if (isset($payload['currency'])) {
            $payload['currency'] = mb_strtoupper((string) $payload['currency']);
        }
        if ($actionType === 'CRM_ACTIVITY_DRAFT') {
            foreach (['type', 'status', 'priority'] as $key) {
                if (isset($payload[$key])) {
                    $payload[$key] = mb_strtoupper((string) $payload[$key]);
                }
            }
        }
        if ($actionType === 'OUTREACH_SEQUENCE_DRAFT' && isset($payload['steps'])) {
            $payload['steps'] = collect($payload['steps'])->map(fn (array $step): array => [...$step, 'type' => mb_strtoupper((string) ($step['type'] ?? ''))])->all();
        }

        $validated = Validator::make($payload, $this->rules($companyId, $actionType))->validate();
        if ($actionType === 'JOURNAL_DRAFT') {
            $debit = (int) collect($validated['lines'])->sum('debit');
            $credit = (int) collect($validated['lines'])->sum('credit');
            $invalidLine = collect($validated['lines'])->contains(fn (array $line): bool => ($line['debit'] === 0 && $line['credit'] === 0) || ($line['debit'] > 0 && $line['credit'] > 0));
            if ($debit === 0 || $debit !== $credit || $invalidLine) {
                throw ValidationException::withMessages(['lines' => 'A proposed journal draft must contain balanced debit and credit lines, with one side on each line.']);
            }
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    private function rules(string $companyId, string $actionType): array
    {
        $money = ['integer', 'between:0,9007199254740991'];

        return match ($actionType) {
            'CRM_ACTIVITY_DRAFT' => ['related_type' => ['nullable', Rule::in(['account', 'contact', 'lead', 'deal'])], 'related_id' => ['nullable', 'uuid', 'required_with:related_type'], 'owner_id' => ['nullable', 'integer', Rule::exists('company_users', 'user_id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))], 'type' => ['required', Rule::in(['CALL', 'MEETING', 'EMAIL', 'TASK', 'NOTE'])], 'subject' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'due_at' => ['nullable', 'date'], 'status' => ['sometimes', Rule::in(['PENDING', 'COMPLETED', 'CANCELLED'])], 'priority' => ['sometimes', Rule::in(['LOW', 'MEDIUM', 'HIGH'])], 'outcome' => ['nullable', 'string', 'max:5000']],
            'CRM_LEAD_UPDATE' => ['record_id' => ['required', 'uuid', Rule::exists('crm_leads', 'id')->where('company_id', $companyId)], 'changes' => ['required', 'array:first_name,last_name,job_title,email,phone,notes,interest,expected_timeframe'], 'changes.first_name' => ['sometimes', 'string', 'max:120'], 'changes.last_name' => ['sometimes', 'string', 'max:120'], 'changes.job_title' => ['sometimes', 'nullable', 'string', 'max:160'], 'changes.email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'], 'changes.phone' => ['sometimes', 'nullable', 'string', 'max:60'], 'changes.notes' => ['sometimes', 'nullable', 'string', 'max:5000'], 'changes.interest' => ['sometimes', 'nullable', 'string', 'max:2000'], 'changes.expected_timeframe' => ['sometimes', 'nullable', 'date']],
            'CRM_DEAL_UPDATE' => ['record_id' => ['required', 'uuid', Rule::exists('crm_deals', 'id')->where('company_id', $companyId)], 'changes' => ['required', 'array:title,description,expected_close_date,amount,probability_bps'], 'changes.title' => ['sometimes', 'string', 'max:255'], 'changes.description' => ['sometimes', 'nullable', 'string', 'max:5000'], 'changes.expected_close_date' => ['sometimes', 'nullable', 'date'], 'changes.amount' => ['sometimes', ...$money], 'changes.probability_bps' => ['sometimes', 'integer', 'between:0,10000']],
            'INVOICE_DRAFT' => ['customer_id' => ['required', 'uuid', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))], 'invoice_date' => ['required', 'date'], 'due_date' => ['required', 'date', 'after_or_equal:invoice_date'], 'currency' => ['required', 'string', 'size:3'], 'notes' => ['nullable', 'string', 'max:5000'], 'terms' => ['nullable', 'string', 'max:5000'], 'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.item_id' => ['nullable', 'uuid', Rule::exists('inventory_items', 'id')->where('company_id', $companyId)], 'lines.*.item_name' => ['nullable', 'string', 'max:255'], 'lines.*.description' => ['required', 'string', 'max:2000'], 'lines.*.quantity_milli' => ['required', 'integer', 'between:1,1000000000'], 'lines.*.unit' => ['required', 'string', 'max:30'], 'lines.*.unit_price' => ['required', ...$money], 'lines.*.discount' => ['sometimes', ...$money], 'lines.*.tax_rate_bps' => ['sometimes', 'integer', 'between:0,10000'], 'lines.*.other_tax_rate_bps' => ['sometimes', 'integer', 'between:0,10000'], 'lines.*.advance_tax_rate_bps' => ['sometimes', 'integer', 'between:0,10000'], 'lines.*.withholding_tax_rate_bps' => ['sometimes', 'integer', 'between:0,10000'], 'lines.*.sales_type' => ['required', 'string', 'max:60'], 'lines.*.tax_metadata' => ['nullable', 'array']],
            'JOURNAL_DRAFT' => ['posting_date' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:255'], 'description' => ['required', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:2'], 'lines.*.account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('company_id', $companyId)], 'lines.*.description' => ['nullable', 'string', 'max:1000'], 'lines.*.debit' => ['required', ...$money], 'lines.*.credit' => ['required', ...$money]],
            'PURCHASE_ORDER_DRAFT' => ['supplier_id' => ['required', 'uuid', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))], 'order_date' => ['required', 'date'], 'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'], 'currency' => ['required', 'string', 'size:3'], 'reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:5000'], 'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.item_id' => ['nullable', 'uuid', Rule::exists('inventory_items', 'id')->where('company_id', $companyId)], 'lines.*.item_name' => ['nullable', 'string', 'max:255'], 'lines.*.description' => ['required', 'string', 'max:2000'], 'lines.*.procurement_type' => ['required', Rule::in(['goods', 'service'])], 'lines.*.quantity_milli' => ['required', 'integer', 'between:1,1000000000'], 'lines.*.unit' => ['required', 'string', 'max:30'], 'lines.*.unit_price' => ['required', ...$money], 'lines.*.discount' => ['sometimes', ...$money], 'lines.*.tax_rate_bps' => ['sometimes', 'integer', 'between:0,10000'], 'lines.*.expense_account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)->whereIn('type', ['expense', 'asset']))], 'lines.*.metadata' => ['nullable', 'array']],
            'OUTREACH_SEQUENCE_DRAFT' => ['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'sending_identity_id' => ['required', 'uuid', Rule::exists('email_sending_identities', 'id')->where('company_id', $companyId)], 'owner_id' => ['nullable', 'integer', Rule::exists('company_users', 'user_id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))], 'timezone' => ['required', 'timezone'], 'starts_at' => ['nullable', 'date'], 'allowed_weekdays' => ['required', 'array', 'min:1', 'max:7'], 'allowed_weekdays.*' => ['integer', 'between:1,7', 'distinct'], 'send_window_start' => ['required', 'date_format:H:i'], 'send_window_end' => ['required', 'date_format:H:i', 'after:send_window_start'], 'track_opens' => ['sometimes', 'boolean'], 'track_clicks' => ['sometimes', 'boolean'], 'stop_on_reply' => ['sometimes', 'boolean'], 'steps' => ['required', 'array', 'min:1', 'max:50'], 'steps.*.type' => ['required', Rule::in(['EMAIL', 'WAIT'])], 'steps.*.template_id' => ['nullable', 'uuid', Rule::exists('email_templates', 'id')->where('company_id', $companyId)], 'steps.*.subject' => ['nullable', 'string', 'max:998'], 'steps.*.body_text' => ['nullable', 'string', 'max:100000'], 'steps.*.body_html' => ['nullable', 'string', 'max:200000'], 'steps.*.wait_minutes' => ['required', 'integer', 'between:0,525600']],
        };
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function impact(string $actionType, array $payload): array
    {
        return match ($actionType) {
            'INVOICE_DRAFT' => ['summary' => 'Create a draft invoice only; no GL or AR effect.', 'financial' => $this->invoiceCalculation->calculate($payload['lines'])['totals']],
            'JOURNAL_DRAFT' => ['summary' => 'Create an unposted journal draft only; no GL effect.', 'total_debit_minor' => (int) collect($payload['lines'])->sum('debit'), 'total_credit_minor' => (int) collect($payload['lines'])->sum('credit')],
            'PURCHASE_ORDER_DRAFT' => ['summary' => 'Create a draft purchase order only; no inventory, AP, or GL effect.', 'financial' => $this->purchaseCalculation->calculate($payload['lines'])['totals']],
            'OUTREACH_SEQUENCE_DRAFT' => ['summary' => 'Create a draft outreach sequence; no enrollment or sending.', 'step_count' => count($payload['steps'])],
            'CRM_ACTIVITY_DRAFT' => ['summary' => 'Create a CRM activity or task; no financial effect.', 'type' => $payload['type']],
            'CRM_LEAD_UPDATE', 'CRM_DEAL_UPDATE' => ['summary' => 'Update only the reviewed CRM fields; no accounting or outreach execution.', 'fields' => array_keys($payload['changes'])],
        };
    }

    private function perform(AiActionProposal $proposal, User $user): Model
    {
        $payload = $this->validatePayload($proposal->company_id, $proposal->action_type, $proposal->payload);

        return match ($proposal->action_type) {
            'INVOICE_DRAFT' => $this->invoices->create($proposal->company_id, $user, $payload, 'ai-proposal:'.$proposal->id),
            'JOURNAL_DRAFT' => $this->journals->saveDraft($proposal->company_id, $user, [...$payload, 'status' => 'draft', 'source' => 'ai_action_proposal']),
            'PURCHASE_ORDER_DRAFT' => $this->purchaseOrders->create($proposal->company_id, $user, $payload, 'ai-proposal:'.$proposal->id),
            'OUTREACH_SEQUENCE_DRAFT' => $this->sequences->create($proposal->company_id, $user->id, $payload),
            'CRM_ACTIVITY_DRAFT' => $this->crmActivities->create($proposal->company_id, $user, $payload),
            'CRM_LEAD_UPDATE' => $this->crmRecords->updateLead($proposal->company_id, $user, $payload['record_id'], $payload['changes']),
            'CRM_DEAL_UPDATE' => $this->crmRecords->updateDeal($proposal->company_id, $user, $payload['record_id'], $payload['changes']),
        };
    }

    private function assertPendingAndCurrent(AiActionProposal $proposal): void
    {
        if ($proposal->expires_at->isPast()) {
            $proposal->update(['status' => 'EXPIRED']);
            throw new PlatformException('AI_ACTION_EXPIRED', 'This AI action proposal has expired.', 409);
        }
        if ($proposal->status !== 'PENDING') {
            throw new PlatformException('AI_ACTION_ALREADY_REVIEWED', 'This AI action proposal has already been reviewed.', 409);
        }
    }

    private function expireIfPast(string $companyId, AiActionProposal $proposal): void
    {
        AiActionProposal::query()
            ->where('company_id', $companyId)
            ->whereKey($proposal->id)
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->where('expires_at', '<=', now())
            ->update(['status' => 'EXPIRED']);
        if ($proposal->fresh()?->status === 'EXPIRED') {
            throw new PlatformException('AI_ACTION_EXPIRED', 'This AI action proposal has expired.', 409);
        }
    }

    /** @param callable(AiActionProposal): void $callback */
    private function lockProposal(string $companyId, AiActionProposal $proposal, callable $callback): AiActionProposal
    {
        return DB::transaction(function () use ($companyId, $proposal, $callback): AiActionProposal {
            $locked = AiActionProposal::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($proposal->id);
            $callback($locked);

            return $locked->fresh();
        });
    }

    /** @param array<string, mixed> $payload */
    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function auditValues(AiActionProposal $proposal): array
    {
        return ['id' => $proposal->id, 'action_type' => $proposal->action_type, 'status' => $proposal->status, 'payload_checksum' => $proposal->payload_checksum, 'expires_at' => $proposal->expires_at?->toIso8601String()];
    }
}
