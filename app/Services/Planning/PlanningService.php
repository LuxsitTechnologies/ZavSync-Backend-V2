<?php

namespace App\Services\Planning;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Budget;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Forecast;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanningService
{
    public function __construct(private readonly FinancialReportService $reports) {}

    /** @param array<string, mixed> $data */
    public function createFiscalYear(string $companyId, User $user, array $data): FiscalYear
    {
        return DB::transaction(function () use ($companyId, $user, $data): FiscalYear {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            if ($company->currency !== mb_strtoupper($data['currency'])) {
                throw ValidationException::withMessages(['currency' => 'Fiscal-year currency must match the company currency until full FX planning is implemented.']);
            }
            $overlap = FiscalYear::query()->where('company_id', $companyId)->whereDate('start_date', '<=', $data['end_date'])->whereDate('end_date', '>=', $data['start_date'])->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['start_date' => 'Fiscal years cannot overlap.']);
            }
            $year = FiscalYear::query()->create([...$data, 'company_id' => $companyId, 'currency' => mb_strtoupper($data['currency']), 'status' => 'open', 'created_by' => $user->id]);
            AccountingPeriod::query()->where('company_id', $companyId)->whereNull('fiscal_year_id')->whereDate('start_date', '>=', $year->start_date)->whereDate('end_date', '<=', $year->end_date)->update(['fiscal_year_id' => $year->id]);

            return $year->load('periods');
        });
    }

    /** @param array<string, mixed> $data */
    public function createBudget(string $companyId, User $user, array $data): Budget
    {
        return DB::transaction(function () use ($companyId, $user, $data): Budget {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $year = $this->fiscalYear($companyId, $data['fiscal_year_id']);
            $this->assertCurrency($year, $data['currency']);
            $budget = Budget::query()->create([
                'company_id' => $companyId, 'fiscal_year_id' => $year->id, 'name' => $data['name'], 'version' => 1,
                'status' => 'draft', 'currency' => mb_strtoupper($data['currency']), 'description' => $data['description'] ?? null, 'created_by' => $user->id,
            ]);
            $this->replaceBudgetLines($companyId, $budget, $data['lines'] ?? []);

            return $budget->load(['fiscalYear.periods', 'lines.account', 'lines.period']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateBudget(string $companyId, Budget $budget, array $data): Budget
    {
        return DB::transaction(function () use ($companyId, $budget, $data): Budget {
            $budget = Budget::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($budget->id);
            $this->assertDraft($budget);
            $budget->update(array_filter(['name' => $data['name'] ?? null, 'description' => array_key_exists('description', $data) ? $data['description'] : null], fn ($value, $key): bool => $value !== null || $key === 'description', ARRAY_FILTER_USE_BOTH));
            if (array_key_exists('lines', $data)) {
                $this->replaceBudgetLines($companyId, $budget, $data['lines']);
            }

            return $budget->load(['fiscalYear.periods', 'lines.account', 'lines.period']);
        });
    }

    public function submitBudget(string $companyId, User $user, Budget $budget): Budget
    {
        return DB::transaction(function () use ($companyId, $user, $budget): Budget {
            $budget = Budget::query()->where('company_id', $companyId)->withCount('lines')->lockForUpdate()->findOrFail($budget->id);
            $this->assertDraft($budget);
            if ($budget->lines_count === 0) {
                throw ValidationException::withMessages(['lines' => 'A budget requires at least one line before submission.']);
            }
            $budget->update(['status' => 'submitted', 'submitted_by' => $user->id, 'submitted_at' => now()]);

            return $budget->fresh()->load(['fiscalYear', 'lines.account', 'lines.period']);
        });
    }

    public function approveBudget(string $companyId, User $user, Budget $budget): Budget
    {
        return $this->transitionBudget($companyId, $budget, 'submitted', ['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
    }

    public function activateBudget(string $companyId, User $user, Budget $budget): Budget
    {
        return DB::transaction(function () use ($companyId, $user, $budget): Budget {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $budget = Budget::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($budget->id);
            if ($budget->status !== 'approved' && ! ($budget->status === 'active' && $budget->is_active)) {
                throw ValidationException::withMessages(['status' => 'Only an approved budget can be activated.']);
            }
            Budget::query()->where('company_id', $companyId)->where('fiscal_year_id', $budget->fiscal_year_id)->whereKeyNot($budget->id)->where('is_active', true)->update(['is_active' => false, 'status' => 'archived']);
            $budget->update(['status' => 'active', 'is_active' => true, 'activated_by' => $user->id, 'activated_at' => now()]);

            return $budget->fresh()->load(['fiscalYear', 'lines.account', 'lines.period']);
        });
    }

    public function reviseBudget(string $companyId, User $user, Budget $budget): Budget
    {
        return DB::transaction(function () use ($companyId, $user, $budget): Budget {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $budget = Budget::query()->where('company_id', $companyId)->with('lines')->lockForUpdate()->findOrFail($budget->id);
            if (! in_array($budget->status, ['approved', 'active', 'archived'], true)) {
                throw ValidationException::withMessages(['status' => 'Only approved budget history can be revised.']);
            }
            $version = (int) Budget::query()->where('company_id', $companyId)->where('fiscal_year_id', $budget->fiscal_year_id)->where('name', $budget->name)->max('version') + 1;
            $revision = Budget::query()->create($budget->only(['company_id', 'fiscal_year_id', 'name', 'currency', 'description']) + ['based_on_budget_id' => $budget->id, 'version' => $version, 'status' => 'draft', 'is_active' => false, 'created_by' => $user->id]);
            $revision->lines()->createMany($budget->lines->map(fn ($line): array => ['company_id' => $companyId, 'account_id' => $line->account_id, 'accounting_period_id' => $line->accounting_period_id, 'amount' => $line->amount])->all());

            return $revision->load(['fiscalYear', 'lines.account', 'lines.period']);
        });
    }

    /** @return array<string, mixed> */
    public function budgetActual(string $companyId, Budget $budget, ?string $from = null, ?string $to = null): array
    {
        $budget = Budget::query()->where('company_id', $companyId)->with(['fiscalYear.periods', 'lines.account', 'lines.period'])->findOrFail($budget->id);
        $from ??= $budget->fiscalYear->start_date->format('Y-m-d');
        $to ??= $budget->fiscalYear->end_date->format('Y-m-d');
        $lines = $budget->lines->filter(fn ($line): bool => $line->period->end_date->format('Y-m-d') >= $from && $line->period->start_date->format('Y-m-d') <= $to);
        $actuals = $this->reports->profitAndLossActuals($companyId, $from, $to);
        $lineAmounts = $lines->groupBy('account_id')->map(fn (Collection $accountLines): int => (int) $accountLines->sum('amount'));
        $accountIds = $lineAmounts->keys()->merge($actuals->keys())->unique();
        $accounts = Account::query()->where('company_id', $companyId)->whereIn('id', $accountIds)->whereIn('type', ['revenue', 'expense'])->orderBy('code')->get();
        $rows = $accounts->map(function (Account $account) use ($lineAmounts, $actuals): array {
            $accountId = $account->id;
            $budgetAmount = (int) ($lineAmounts->get($accountId) ?? 0);
            $actual = (int) ($actuals->get($accountId)->amount ?? 0);
            $variance = $actual - $budgetAmount;

            return ['account_id' => $accountId, 'account_code' => $account->code, 'account_name' => $account->name, 'type' => $account->type, 'subtype' => $account->subtype, 'budget' => $budgetAmount, 'actual' => $actual, 'variance' => $variance, 'variance_percentage_bps' => $budgetAmount === 0 ? null : intdiv($variance * 10000, abs($budgetAmount)), 'favorable' => $account->type === 'revenue' ? $variance >= 0 : $variance <= 0];
        })->values();

        return ['budget_id' => $budget->id, 'from' => $from, 'to' => $to, 'rows' => $rows->all(), 'profit_and_loss' => $this->planningProfitAndLoss($rows, 'budget', 'actual')];
    }

    /** @param array<string, mixed> $data */
    public function createForecast(string $companyId, User $user, array $data): Forecast
    {
        return DB::transaction(function () use ($companyId, $user, $data): Forecast {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $year = $this->fiscalYear($companyId, $data['fiscal_year_id']);
            $this->assertCurrency($year, $data['currency']);
            $sourceBudget = isset($data['based_on_budget_id']) ? Budget::query()->where('company_id', $companyId)->where('fiscal_year_id', $year->id)->with('lines')->findOrFail($data['based_on_budget_id']) : null;
            $sourceForecast = isset($data['based_on_forecast_id']) ? Forecast::query()->where('company_id', $companyId)->where('fiscal_year_id', $year->id)->with('lines')->findOrFail($data['based_on_forecast_id']) : null;
            if ($sourceBudget !== null && $sourceForecast !== null) {
                throw ValidationException::withMessages(['based_on_forecast_id' => 'Choose either a budget or a prior forecast as the baseline.']);
            }
            $version = (int) Forecast::query()->where('company_id', $companyId)->where('fiscal_year_id', $year->id)->where('name', $data['name'])->max('version') + 1;
            $forecast = Forecast::query()->create(['company_id' => $companyId, 'fiscal_year_id' => $year->id, 'based_on_budget_id' => $sourceBudget?->id, 'based_on_forecast_id' => $sourceForecast?->id, 'name' => $data['name'], 'version' => $version, 'status' => 'draft', 'currency' => mb_strtoupper($data['currency']), 'actuals_through' => $data['actuals_through'] ?? null, 'description' => $data['description'] ?? null, 'created_by' => $user->id]);
            $sourceLines = $sourceBudget?->lines ?? $sourceForecast?->lines;
            if ($sourceLines !== null) {
                $forecast->lines()->createMany($sourceLines->map(fn ($line): array => ['company_id' => $companyId, 'account_id' => $line->account_id, 'accounting_period_id' => $line->accounting_period_id, 'amount' => $line->amount])->all());
            }
            if (array_key_exists('lines', $data)) {
                $this->replaceForecastLines($companyId, $forecast, $data['lines']);
            }

            return $forecast->load(['fiscalYear.periods', 'lines.account', 'lines.period']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateForecast(string $companyId, Forecast $forecast, array $data): Forecast
    {
        return DB::transaction(function () use ($companyId, $forecast, $data): Forecast {
            $forecast = Forecast::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($forecast->id);
            if ($forecast->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only a draft forecast can be edited.']);
            }
            $forecast->update(array_intersect_key($data, array_flip(['name', 'description', 'actuals_through'])));
            if (array_key_exists('lines', $data)) {
                $this->replaceForecastLines($companyId, $forecast, $data['lines']);
            }

            return $forecast->load(['fiscalYear.periods', 'lines.account', 'lines.period']);
        });
    }

    public function activateForecast(string $companyId, User $user, Forecast $forecast): Forecast
    {
        return DB::transaction(function () use ($companyId, $user, $forecast): Forecast {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $forecast = Forecast::query()->where('company_id', $companyId)->withCount('lines')->lockForUpdate()->findOrFail($forecast->id);
            if ($forecast->status !== 'draft' || $forecast->lines_count === 0) {
                throw ValidationException::withMessages(['status' => 'Only a populated draft forecast can be activated.']);
            }
            Forecast::query()->where('company_id', $companyId)->where('fiscal_year_id', $forecast->fiscal_year_id)->whereKeyNot($forecast->id)->where('is_active', true)->update(['is_active' => false, 'status' => 'archived']);
            $forecast->update(['status' => 'active', 'is_active' => true, 'activated_by' => $user->id, 'activated_at' => now()]);

            return $forecast->fresh()->load(['fiscalYear', 'lines.account', 'lines.period']);
        });
    }

    /** @return array<string, mixed> */
    public function forecastProjection(string $companyId, Forecast $forecast, ?string $asOf = null): array
    {
        $forecast = Forecast::query()->where('company_id', $companyId)->with(['fiscalYear.periods', 'lines.account', 'lines.period'])->findOrFail($forecast->id);
        $cutoff = $asOf ?? $forecast->actuals_through?->format('Y-m-d') ?? now()->toDateString();
        $actuals = $this->reports->profitAndLossActuals($companyId, $forecast->fiscalYear->start_date->format('Y-m-d'), min($cutoff, $forecast->fiscalYear->end_date->format('Y-m-d')));
        $rows = $forecast->lines->groupBy('account_id')->map(function (Collection $accountLines, string $accountId) use ($actuals, $cutoff): array {
            $account = $accountLines->first()->account;
            $actual = (int) ($actuals->get($accountId)->amount ?? 0);
            $remainingForecast = (int) $accountLines->filter(fn ($line): bool => $line->period->end_date->format('Y-m-d') > $cutoff)->sum('amount');
            $completedForecast = (int) $accountLines->filter(fn ($line): bool => $line->period->end_date->format('Y-m-d') <= $cutoff)->sum('amount');
            $variance = $actual - $completedForecast;

            return ['account_id' => $accountId, 'account_code' => $account->code, 'account_name' => $account->name, 'type' => $account->type, 'subtype' => $account->subtype, 'actual_completed' => $actual, 'forecast_completed' => $completedForecast, 'remaining_forecast' => $remainingForecast, 'full_year_projection' => $actual + $remainingForecast, 'variance' => $variance, 'variance_percentage_bps' => $completedForecast === 0 ? null : intdiv($variance * 10000, abs($completedForecast))];
        })->sortBy('account_code')->values();

        return ['forecast_id' => $forecast->id, 'actuals_through' => $cutoff, 'rows' => $rows->all(), 'profit_and_loss' => $this->forecastProfitAndLoss($rows)];
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function replaceBudgetLines(string $companyId, Budget $budget, array $lines): void
    {
        $periods = AccountingPeriod::query()->where('company_id', $companyId)->where('fiscal_year_id', $budget->fiscal_year_id)->orderBy('start_date')->get();
        if ($periods->isEmpty() && $lines !== []) {
            throw ValidationException::withMessages(['lines' => 'Create accounting periods for the fiscal year before adding budget lines.']);
        }
        $accountIds = collect($lines)->pluck('account_id');
        if ($accountIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Each account may appear only once in a budget version.']);
        }
        $accounts = Account::query()->where('company_id', $companyId)->whereIn('id', $accountIds)->where('is_active', true)->whereIn('type', ['revenue', 'expense'])->get()->keyBy('id');
        if ($accounts->count() !== $accountIds->unique()->count()) {
            throw ValidationException::withMessages(['lines' => 'Every budget account must be an active revenue or expense account in this company.']);
        }
        $rows = [];
        foreach ($lines as $line) {
            $allocations = $this->budgetAllocations($line, $periods);
            foreach ($allocations as $periodId => $amount) {
                $rows[] = ['company_id' => $companyId, 'account_id' => $line['account_id'], 'accounting_period_id' => $periodId, 'amount' => $amount];
            }
        }
        $budget->lines()->delete();
        $budget->lines()->createMany($rows);
    }

    /** @param array<string, mixed> $line @param Collection<int, AccountingPeriod> $periods @return array<string, int> */
    private function budgetAllocations(array $line, Collection $periods): array
    {
        if (($line['distribution'] ?? null) === 'equal' || (! isset($line['periods']) && isset($line['annual_amount']))) {
            $annual = (int) ($line['annual_amount'] ?? 0);
            if ($annual < 0) {
                throw ValidationException::withMessages(['annual_amount' => 'Budget amounts cannot be negative.']);
            }
            $base = intdiv($annual, $periods->count());
            $remainder = $annual % $periods->count();

            return $periods->values()->mapWithKeys(fn (AccountingPeriod $period, int $index): array => [$period->id => $base + ($index < $remainder ? 1 : 0)])->all();
        }
        $validPeriods = $periods->keyBy('id');
        $allocations = [];
        foreach ($line['periods'] ?? [] as $period) {
            if (! $validPeriods->has($period['period_id']) || isset($allocations[$period['period_id']]) || (int) $period['amount'] < 0) {
                throw ValidationException::withMessages(['periods' => 'Budget periods must be unique, non-negative, and belong to the selected fiscal year.']);
            }
            $allocations[$period['period_id']] = (int) $period['amount'];
        }
        if (isset($line['annual_amount']) && array_sum($allocations) !== (int) $line['annual_amount']) {
            throw ValidationException::withMessages(['annual_amount' => 'Annual budget must equal the sum of monthly amounts.']);
        }

        return $allocations;
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function replaceForecastLines(string $companyId, Forecast $forecast, array $lines): void
    {
        $periodIds = AccountingPeriod::query()->where('company_id', $companyId)->where('fiscal_year_id', $forecast->fiscal_year_id)->pluck('id');
        $accountIds = collect($lines)->pluck('account_id')->unique();
        $validAccounts = Account::query()->where('company_id', $companyId)->whereIn('id', $accountIds)->where('is_active', true)->whereIn('type', ['revenue', 'expense'])->count();
        if ($validAccounts !== $accountIds->count()) {
            throw ValidationException::withMessages(['lines' => 'Every forecast account must be an active revenue or expense account in this company.']);
        }
        $seen = [];
        $rows = [];
        foreach ($lines as $line) {
            $key = $line['account_id'].'|'.$line['period_id'];
            if (! $periodIds->contains($line['period_id']) || isset($seen[$key])) {
                throw ValidationException::withMessages(['lines' => 'Forecast account and period combinations must be unique and belong to the fiscal year.']);
            }
            $seen[$key] = true;
            $rows[] = ['company_id' => $companyId, 'account_id' => $line['account_id'], 'accounting_period_id' => $line['period_id'], 'amount' => (int) $line['amount']];
        }
        $forecast->lines()->delete();
        $forecast->lines()->createMany($rows);
    }

    private function fiscalYear(string $companyId, string $id): FiscalYear
    {
        return FiscalYear::query()->where('company_id', $companyId)->where('status', 'open')->with('periods')->findOrFail($id);
    }

    private function assertCurrency(FiscalYear $year, string $currency): void
    {
        if ($year->currency !== mb_strtoupper($currency)) {
            throw ValidationException::withMessages(['currency' => 'Planning currency must match the fiscal year currency.']);
        }
    }

    private function assertDraft(Budget $budget): void
    {
        if ($budget->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft budget can be edited. Create a revision for approved history.']);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function transitionBudget(string $companyId, Budget $budget, string $from, array $attributes): Budget
    {
        return DB::transaction(function () use ($companyId, $budget, $from, $attributes): Budget {
            $budget = Budget::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($budget->id);
            if ($budget->status !== $from) {
                throw ValidationException::withMessages(['status' => "Budget must be {$from} for this action."]);
            }
            $budget->update($attributes);

            return $budget->fresh()->load(['fiscalYear', 'lines.account', 'lines.period']);
        });
    }

    /** @param Collection<int, array<string, mixed>> $rows @return array<string, int> */
    private function planningProfitAndLoss(Collection $rows, string $planKey, string $actualKey): array
    {
        $totals = [];
        foreach ([$planKey, $actualKey] as $key) {
            $revenue = $rows->where('type', 'revenue')->whereNotIn('subtype', ['other_income'])->sum($key);
            $cogs = $rows->where('subtype', 'cost_of_sales')->sum($key);
            $operating = $rows->where('type', 'expense')->whereNotIn('subtype', ['cost_of_sales', 'other_expense'])->sum($key);
            $otherIncome = $rows->where('subtype', 'other_income')->sum($key);
            $otherExpense = $rows->where('subtype', 'other_expense')->sum($key);
            $totals[$key] = ['revenue' => $revenue, 'cost_of_sales' => $cogs, 'gross_profit' => $revenue - $cogs, 'operating_expenses' => $operating, 'operating_profit' => $revenue - $cogs - $operating, 'other_income' => $otherIncome, 'other_expenses' => $otherExpense, 'net_profit' => $revenue - $cogs - $operating + $otherIncome - $otherExpense];
        }

        return $totals;
    }

    /** @param Collection<int, array<string, mixed>> $rows @return array<string, mixed> */
    private function forecastProfitAndLoss(Collection $rows): array
    {
        $mapped = $rows->map(fn (array $row): array => [...$row, 'projection' => $row['full_year_projection']]);

        return $this->planningProfitAndLoss($mapped, 'projection', 'actual_completed');
    }
}
