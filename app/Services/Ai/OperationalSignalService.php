<?php

namespace App\Services\Ai;

use App\Models\AccountingPeriod;
use App\Models\AiProviderConfiguration;
use App\Models\AnomalyResult;
use App\Models\BankTransaction;
use App\Models\CompanyUser;
use App\Models\CrmActivity;
use App\Models\CrmDeal;
use App\Models\OperationalPrioritySignal;
use App\Models\OperationalSignalEvent;
use App\Models\PayrollBatch;
use App\Models\PlatformNotification;
use App\Models\ScheduledIntelligenceRun;
use App\Models\User;
use App\Services\Accounting\AccountingCloseService;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Banking\BankingReportingService;
use App\Services\Inventory\InventoryReportingService;
use App\Services\Outreach\OutreachReportService;
use App\Services\Payroll\PayrollReportingService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationalSignalService
{
    /** @var array<string,string> */
    public const SOURCE_PERMISSIONS = ['accounting' => 'accounting.view', 'receivables' => 'accounting.view', 'payables' => 'payables.view', 'banking' => 'banking.view', 'inventory' => 'inventory.view', 'payroll' => 'payroll.reports', 'crm' => 'crm.view', 'outreach' => 'outreach.reports.view', 'platform' => 'platform.jobs.view', 'ai' => 'ai.usage.view'];

    public function __construct(
        private readonly PriorityScoringService $scoring,
        private readonly AccountsReceivableService $receivables,
        private readonly AccountsPayableService $payables,
        private readonly BankingReportingService $banking,
        private readonly InventoryReportingService $inventory,
        private readonly PayrollReportingService $payroll,
        private readonly OutreachReportService $outreach,
        private readonly AccountingCloseService $close,
        private readonly EntitlementService $entitlements,
        private readonly NotificationService $notifications,
    ) {}

    /** @return array{detected:int,resolved:int,fingerprints:array<int,string>} */
    public function refresh(string $companyId): array
    {
        $modules = $this->entitlements->enabledModules($companyId);
        $signals = [];
        if (in_array('accounting', $modules, true)) {
            $signals = [...$signals, ...$this->accountingSignals($companyId)];
        }
        if (in_array('receivables', $modules, true)) {
            $signals = [...$signals, ...$this->receivableSignals($companyId)];
        }
        if (in_array('payables', $modules, true)) {
            $signals = [...$signals, ...$this->payableSignals($companyId)];
        }
        if (in_array('banking', $modules, true)) {
            $signals = [...$signals, ...$this->bankingSignals($companyId)];
        }
        if (in_array('inventory', $modules, true)) {
            $signals = [...$signals, ...$this->inventorySignals($companyId)];
        }
        if (in_array('payroll', $modules, true)) {
            $signals = [...$signals, ...$this->payrollSignals($companyId)];
        }
        if (in_array('crm', $modules, true)) {
            $signals = [...$signals, ...$this->crmSignals($companyId)];
        }
        if (in_array('outreach', $modules, true)) {
            $signals = [...$signals, ...$this->outreachSignals($companyId)];
        }
        $signals = [...$signals, ...$this->platformSignals($companyId)];
        $fingerprints = [];
        foreach ($signals as $definition) {
            $signal = $this->record($companyId, $definition);
            $fingerprints[] = $signal->fingerprint;
        }
        $stale = OperationalPrioritySignal::query()->where('company_id', $companyId)->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])->when($fingerprints !== [], fn ($query) => $query->whereNotIn('fingerprint', $fingerprints))->get();
        foreach ($stale as $signal) {
            $from = $signal->status;
            $signal->update(['status' => 'RESOLVED', 'resolved_at' => now(), 'resolution_note' => 'The underlying condition was not present during the latest refresh.']);
            OperationalSignalEvent::query()->create(['company_id' => $companyId, 'operational_priority_signal_id' => $signal->id, 'event_type' => 'AUTO_RESOLVED', 'from_status' => $from, 'to_status' => 'RESOLVED', 'metadata' => ['reason' => 'condition_absent']]);
        }

        return ['detected' => count($fingerprints), 'resolved' => $stale->count(), 'fingerprints' => $fingerprints];
    }

    /** @param array{action:string,note?:string|null,assigned_user_id?:int|null} $data */
    public function transition(string $companyId, User $user, OperationalPrioritySignal $signal, array $data): OperationalPrioritySignal
    {
        return DB::transaction(function () use ($companyId, $user, $signal, $data): OperationalPrioritySignal {
            $signal = OperationalPrioritySignal::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($signal->id);
            $from = $signal->status;
            $action = $data['action'];
            if ($action === 'ASSIGN') {
                $signal->update(['assigned_user_id' => $data['assigned_user_id'] ?? null]);
                $event = 'ASSIGNED';
                $to = $from;
            } else {
                $to = match ($action) {
                    'ACKNOWLEDGE' => 'ACKNOWLEDGED', 'RESOLVE' => 'RESOLVED', 'DISMISS' => 'DISMISSED', 'REOPEN' => 'OPEN'
                };
                $allowed = ['OPEN' => ['ACKNOWLEDGED', 'RESOLVED', 'DISMISSED'], 'ACKNOWLEDGED' => ['RESOLVED', 'DISMISSED', 'OPEN'], 'RESOLVED' => ['OPEN'], 'DISMISSED' => ['OPEN']];
                if (! in_array($to, $allowed[$from] ?? [], true)) {
                    throw ValidationException::withMessages(['action' => "Signal cannot transition from {$from} to {$to}."]);
                }
                $attributes = ['status' => $to, 'resolution_note' => $data['note'] ?? null];
                if ($to === 'ACKNOWLEDGED') {
                    $attributes += ['acknowledged_by' => $user->id, 'acknowledged_at' => now()];
                }
                if ($to === 'RESOLVED') {
                    $attributes += ['resolved_by' => $user->id, 'resolved_at' => now()];
                }
                if ($to === 'DISMISSED') {
                    $attributes += ['dismissed_by' => $user->id, 'dismissed_at' => now()];
                }
                if ($to === 'OPEN') {
                    $attributes += ['resolved_by' => null, 'resolved_at' => null, 'dismissed_by' => null, 'dismissed_at' => null];
                }
                $signal->update($attributes);
                $event = $action;
            }
            OperationalSignalEvent::query()->create(['company_id' => $companyId, 'operational_priority_signal_id' => $signal->id, 'actor_id' => $user->id, 'event_type' => $event, 'from_status' => $from, 'to_status' => $to, 'metadata' => ['note' => $data['note'] ?? null, 'assigned_user_id' => $data['assigned_user_id'] ?? null]]);

            return $signal->fresh(['assignedUser:id,name']);
        });
    }

    /** @param array<string,mixed> $definition */
    private function record(string $companyId, array $definition): OperationalPrioritySignal
    {
        $fingerprint = hash('sha256', implode('|', [$definition['source_module'], $definition['source_type'], $definition['source_id'] ?? 'company', $definition['condition']]));
        $score = $this->scoring->score($definition['factors']);
        $existing = OperationalPrioritySignal::query()->where('company_id', $companyId)->where('fingerprint', $fingerprint)->first();
        $wasNew = $existing === null;
        $signal = OperationalPrioritySignal::query()->updateOrCreate(['company_id' => $companyId, 'fingerprint' => $fingerprint], ['category' => $definition['category'], 'source_module' => $definition['source_module'], 'source_type' => $definition['source_type'], 'source_id' => $definition['source_id'] ?? null, 'title' => $definition['title'], 'description' => $definition['description'], 'severity' => $definition['factors']['severity'], 'priority_score' => $score['score'], 'confidence_bps' => $definition['factors']['confidence_bps'] ?? 10000, 'status' => in_array($existing?->status, ['ACKNOWLEDGED', 'DISMISSED'], true) ? $existing->status : 'OPEN', 'supporting_metrics' => $definition['metrics'], 'score_breakdown' => $score['breakdown'], 'explanation_metadata' => ['required_permission' => self::SOURCE_PERMISSIONS[$definition['source_module']] ?? 'intelligence.view', 'condition' => $definition['condition']], 'related_url' => $definition['related_url'], 'detected_at' => $existing?->detected_at ?? now(), 'effective_at' => $definition['effective_at'] ?? now(), 'due_at' => $definition['due_at'] ?? null, 'last_seen_at' => now(), 'resolved_by' => null, 'resolved_at' => null, 'resolution_note' => null]);
        if ($wasNew) {
            OperationalSignalEvent::query()->create(['company_id' => $companyId, 'operational_priority_signal_id' => $signal->id, 'event_type' => 'DETECTED', 'to_status' => 'OPEN', 'metadata' => ['score' => $score['score']]]);
            $this->notify($signal);
        } elseif ($existing->status === 'RESOLVED' && $signal->status === 'OPEN') {
            OperationalSignalEvent::query()->create(['company_id' => $companyId, 'operational_priority_signal_id' => $signal->id, 'event_type' => 'AUTO_REOPENED', 'from_status' => 'RESOLVED', 'to_status' => 'OPEN', 'metadata' => ['score' => $score['score']]]);
            $this->notify($signal);
        }

        return $signal;
    }

    /** @return array<int,array<string,mixed>> */
    private function accountingSignals(string $companyId): array
    {
        $signals = AnomalyResult::query()->where('company_id', $companyId)->where('source_module', 'accounting')->where('status', 'ACTIVE')->latest('evaluated_at')->limit(20)->get()->map(fn (AnomalyResult $result): array => ['category' => 'ACCOUNTING', 'source_module' => 'accounting', 'source_type' => 'anomaly_result', 'source_id' => $result->id, 'condition' => 'anomaly:'.$result->fingerprint, 'title' => 'Unusual '.str_replace('_', ' ', $result->metric), 'description' => $result->explanation, 'factors' => ['severity' => abs((int) $result->deviation_bps) >= 5000 ? 'HIGH' : 'WARNING', 'amount_minor' => abs($result->deviation_value), 'deviation_bps' => (int) $result->deviation_bps, 'confidence_bps' => 10000], 'metrics' => $result->only(['metric', 'method', 'observed_value', 'expected_value', 'deviation_value', 'deviation_bps', 'threshold_bps', 'sample_size', 'window_start', 'window_end']), 'related_url' => '/ai/analytics', 'effective_at' => $result->evaluated_at])->all();
        $period = AccountingPeriod::query()->where('company_id', $companyId)->where('status', 'open')->orderBy('start_date')->first();
        if ($period === null) {
            return $signals;
        }
        $readiness = $this->close->periodReadiness($companyId, $period);

        return [...$signals, ...collect($readiness['checks'])->filter(fn (array $check): bool => ! $check['passed'] && $check['severity'] === 'BLOCKER')->map(fn (array $check): array => ['category' => 'ACCOUNTING', 'source_module' => 'accounting', 'source_type' => 'accounting_period', 'source_id' => $period->id, 'condition' => $check['key'], 'title' => $check['label'], 'description' => $check['message'], 'factors' => ['severity' => 'CRITICAL', 'integrity_risk' => true, 'amount_minor' => abs((int) ($check['value'] ?? 0))], 'metrics' => $check, 'related_url' => '/accounting/periods', 'due_at' => $period->end_date])->values()->all()];
    }

    /** @return array<int,array<string,mixed>> */
    private function receivableSignals(string $companyId): array
    {
        return collect($this->receivables->aging($companyId, null, today()->toDateString()))->filter(fn (array $row): bool => (int) $row['d1_30'] + (int) $row['d31_60'] + (int) $row['d61_90'] + (int) $row['d90_plus'] > 0)->map(function (array $row): array {
            $overdue = (int) $row['d1_30'] + (int) $row['d31_60'] + (int) $row['d61_90'] + (int) $row['d90_plus'];
            $days = (int) $row['d90_plus'] > 0 ? 91 : ((int) $row['d61_90'] > 0 ? 61 : ((int) $row['d31_60'] > 0 ? 31 : 1));

            return ['category' => 'RECEIVABLES', 'source_module' => 'receivables', 'source_type' => 'customer', 'source_id' => $row['party_id'], 'condition' => 'overdue_balance', 'title' => 'Overdue receivable — '.$row['party_name'], 'description' => 'The customer has an overdue receivable balance requiring collection review.', 'factors' => ['severity' => $days > 60 ? 'HIGH' : 'WARNING', 'amount_minor' => $overdue, 'overdue_days' => $days], 'metrics' => $row + ['overdue_minor' => $overdue], 'related_url' => '/accounting/receivables'];
        })->values()->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function payableSignals(string $companyId): array
    {
        return collect($this->payables->aging($companyId, null, today()->toDateString()))->filter(fn (array $row): bool => (int) $row['d1_30'] + (int) $row['d31_60'] + (int) $row['d61_90'] + (int) $row['d90_plus'] > 0)->map(function (array $row): array {
            $overdue = (int) $row['d1_30'] + (int) $row['d31_60'] + (int) $row['d61_90'] + (int) $row['d90_plus'];

            return ['category' => 'PAYABLES', 'source_module' => 'payables', 'source_type' => 'supplier', 'source_id' => $row['party_id'], 'condition' => 'overdue_balance', 'title' => 'Overdue supplier balance — '.$row['party_name'], 'description' => 'The supplier has an overdue balance requiring cash-planning review.', 'factors' => ['severity' => 'HIGH', 'amount_minor' => $overdue, 'overdue_days' => 31], 'metrics' => $row + ['overdue_minor' => $overdue], 'related_url' => '/accounting/payables'];
        })->values()->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function bankingSignals(string $companyId): array
    {
        $signals = [];
        $count = BankTransaction::query()->where('company_id', $companyId)->whereIn('status', ['unmatched', 'suggested', 'partially_matched'])->count();
        if ($count > 0) {
            $signals[] = ['category' => 'BANKING', 'source_module' => 'banking', 'source_type' => 'company', 'condition' => 'unreconciled_transactions', 'title' => 'Unreconciled bank transactions', 'description' => "{$count} bank transactions require reconciliation.", 'factors' => ['severity' => 'HIGH', 'integrity_risk' => true], 'metrics' => ['count' => $count], 'related_url' => '/banking/reconciliation'];
        }
        $cash = $this->banking->currentCash($companyId);
        if ($cash['total'] !== null && (int) $cash['total'] <= 0) {
            $signals[] = ['category' => 'BANKING', 'source_module' => 'banking', 'source_type' => 'company', 'condition' => 'low_cash', 'title' => 'Low cash position', 'description' => 'The current single-currency cash position is zero or negative.', 'factors' => ['severity' => 'CRITICAL', 'amount_minor' => abs((int) $cash['total']), 'deadline_days' => 0], 'metrics' => $cash, 'related_url' => '/accounting/cash-flow'];
        }

        return $signals;
    }

    /** @return array<int,array<string,mixed>> */
    private function inventorySignals(string $companyId): array
    {
        $signals = $this->inventory->lowStock($companyId)->map(fn (array $row): array => ['category' => 'INVENTORY', 'source_module' => 'inventory', 'source_type' => 'inventory_item', 'source_id' => $row['id'], 'condition' => $row['status'], 'title' => ($row['status'] === 'out_of_stock' ? 'Out of stock — ' : 'Low stock — ').$row['name'], 'description' => 'Inventory quantity is at or below the configured reorder level.', 'factors' => ['severity' => $row['status'] === 'out_of_stock' ? 'HIGH' : 'WARNING'], 'metrics' => $row, 'related_url' => '/inventory'])->all();
        $reconciliation = $this->inventory->reconciliation($companyId, ['as_of' => today()->toDateString()]);
        if ((int) $reconciliation['difference'] !== 0) {
            $signals[] = ['category' => 'INVENTORY', 'source_module' => 'inventory', 'source_type' => 'company', 'condition' => 'gl_difference', 'title' => 'Inventory and GL difference', 'description' => $reconciliation['explanation'], 'factors' => ['severity' => 'CRITICAL', 'integrity_risk' => true, 'amount_minor' => abs((int) $reconciliation['difference'])], 'metrics' => $reconciliation, 'related_url' => '/accounting/inventory-ledger'];
        }

        return $signals;
    }

    /** @return array<int,array<string,mixed>> */
    private function payrollSignals(string $companyId): array
    {
        $signals = [];
        $approved = PayrollBatch::query()->where('company_id', $companyId)->where('status', 'APPROVED')->count();
        if ($approved > 0) {
            $signals[] = ['category' => 'PAYROLL', 'source_module' => 'payroll', 'source_type' => 'company', 'condition' => 'approved_unposted', 'title' => 'Approved payroll awaiting posting', 'description' => "{$approved} approved payroll batches have not been posted.", 'factors' => ['severity' => 'HIGH', 'integrity_risk' => true], 'metrics' => ['count' => $approved], 'related_url' => '/payroll/batches'];
        }
        $liabilities = $this->payroll->liabilities($companyId);
        if ((int) $liabilities['outstanding_total'] > 0) {
            $signals[] = ['category' => 'PAYROLL', 'source_module' => 'payroll', 'source_type' => 'company', 'condition' => 'outstanding_liabilities', 'title' => 'Outstanding payroll liabilities', 'description' => 'Posted payroll liabilities remain unsettled.', 'factors' => ['severity' => 'HIGH', 'amount_minor' => (int) $liabilities['outstanding_total']], 'metrics' => ['outstanding_minor' => (int) $liabilities['outstanding_total']], 'related_url' => '/payroll'];
        }

        return $signals;
    }

    /** @return array<int,array<string,mixed>> */
    private function crmSignals(string $companyId): array
    {
        $signals = [];
        $overdue = CrmActivity::query()->where('company_id', $companyId)->where('status', 'PENDING')->where('due_at', '<', now())->count();
        if ($overdue > 0) {
            $signals[] = ['category' => 'CRM', 'source_module' => 'crm', 'source_type' => 'company', 'condition' => 'overdue_followups', 'title' => 'Overdue CRM follow-ups', 'description' => "{$overdue} CRM activities are overdue.", 'factors' => ['severity' => 'WARNING', 'overdue_days' => 1], 'metrics' => ['count' => $overdue], 'related_url' => '/crm/activities'];
        }
        $stale = CrmDeal::query()->where('company_id', $companyId)->where('status', 'OPEN')->where('amount', '>', 0)->where('updated_at', '<', now()->subDays(30))->count();
        if ($stale > 0) {
            $signals[] = ['category' => 'CRM', 'source_module' => 'crm', 'source_type' => 'company', 'condition' => 'stale_deals', 'title' => 'Stale open deals', 'description' => "{$stale} open deals have had no update for 30 days.", 'factors' => ['severity' => 'WARNING', 'overdue_days' => 30], 'metrics' => ['count' => $stale], 'related_url' => '/crm/deals'];
        }

        return $signals;
    }

    /** @return array<int,array<string,mixed>> */
    private function outreachSignals(string $companyId): array
    {
        $summary = $this->outreach->summary($companyId);
        $signals = [];
        $sent = (int) $summary['sent'];
        $bounceBps = $sent === 0 ? 0 : intdiv((int) $summary['bounced'] * 10000, $sent);
        if ($sent >= 10 && $bounceBps >= 500) {
            $signals[] = ['category' => 'OUTREACH', 'source_module' => 'outreach', 'source_type' => 'company', 'condition' => 'high_bounce_rate', 'title' => 'High outreach bounce rate', 'description' => 'Bounce rate exceeds the deterministic 5% threshold.', 'factors' => ['severity' => 'HIGH', 'deviation_bps' => $bounceBps - 500], 'metrics' => ['sent' => $sent, 'bounced' => $summary['bounced'], 'bounce_rate_bps' => $bounceBps], 'related_url' => '/outreach/tracking'];
        }
        if ((int) $summary['failed'] > 0) {
            $signals[] = ['category' => 'OUTREACH', 'source_module' => 'outreach', 'source_type' => 'company', 'condition' => 'failed_messages', 'title' => 'Outreach delivery failures', 'description' => $summary['failed'].' outreach messages failed.', 'factors' => ['severity' => 'WARNING'], 'metrics' => ['failed' => $summary['failed']], 'related_url' => '/outreach/tracking'];
        }

        return $signals;
    }

    /** @return array<int,array<string,mixed>> */
    private function platformSignals(string $companyId): array
    {
        $signals = [];
        $failed = ScheduledIntelligenceRun::query()->where('company_id', $companyId)->where('status', 'FAILED')->where('created_at', '>=', now()->subDays(7))->count();
        if ($failed > 0) {
            $signals[] = ['category' => 'PLATFORM', 'source_module' => 'platform', 'source_type' => 'scheduled_intelligence_run', 'condition' => 'failed_intelligence_jobs', 'title' => 'Failed intelligence jobs', 'description' => "{$failed} company-scoped intelligence jobs failed during the last seven days.", 'factors' => ['severity' => 'HIGH'], 'metrics' => ['count' => $failed, 'window_days' => 7], 'related_url' => '/system-health'];
        }
        $provider = AiProviderConfiguration::query()->where('company_id', $companyId)->first();
        if ($provider !== null && ! $provider->is_enabled) {
            $signals[] = ['category' => 'PLATFORM', 'source_module' => 'ai', 'source_type' => 'ai_provider', 'source_id' => $provider->id, 'condition' => 'provider_disabled', 'title' => 'AI provider disabled', 'description' => 'Deterministic intelligence remains available, but AI explanations are disabled.', 'factors' => ['severity' => 'INFO'], 'metrics' => ['provider' => $provider->provider], 'related_url' => '/knowledge/security'];
        }

        return $signals;
    }

    private function notify(OperationalPrioritySignal $signal): void
    {
        if (! in_array($signal->severity, ['HIGH', 'CRITICAL'], true)) {
            return;
        }
        $type = 'intelligence.signal.'.mb_strtolower($signal->category);
        CompanyUser::query()->where('company_id', $signal->company_id)->where('is_active', true)->with('user')->limit(100)->get()->each(function (CompanyUser $membership) use ($signal, $type): void {
            $user = $membership->user;
            $required = $signal->explanation_metadata['required_permission'] ?? 'intelligence.view';
            if (! $user->hasCompanyPermission($signal->company_id, 'intelligence.view') || ! $user->hasCompanyPermission($signal->company_id, $required)) {
                return;
            }
            $recent = PlatformNotification::query()->where('company_id', $signal->company_id)->where('recipient_id', $user->id)->where('type', $type)->where('related_id', $signal->id)->where('created_at', '>=', now()->subHours(12))->exists();
            if (! $recent) {
                $notification = $this->notifications->create($signal->company_id, $user->id, $type, $signal->title, $signal->description, ['severity' => $signal->severity, 'score' => $signal->priority_score], $signal->related_url);
                if ($notification->exists) {
                    $notification->update(['related_type' => $signal->getMorphClass(), 'related_id' => $signal->id]);
                }
            }
        });
    }
}
