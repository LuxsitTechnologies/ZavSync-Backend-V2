<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Journal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinancialReportService
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function generalLedger(string $companyId, array $filters): array
    {
        $account = isset($filters['account_id']) ? Account::query()->where('company_id', $companyId)->findOrFail($filters['account_id']) : null;
        $openingBalance = $account?->opening_balance ?? 0;
        if ($account !== null && isset($filters['from'])) {
            $openingMovement = $this->postedLines($companyId)
                ->where('line.account_id', $account->id)
                ->whereDate('journals.posting_date', '<', $filters['from'])
                ->selectRaw('COALESCE(SUM(line.debit), 0) AS debit, COALESCE(SUM(line.credit), 0) AS credit')
                ->first();
            $openingBalance += $account->movementBalance((int) $openingMovement->debit, (int) $openingMovement->credit);
        }

        $query = $this->postedLines($companyId);
        $this->applyLedgerFilters($query, $filters);
        $total = (clone $query)->count();
        $totals = (clone $query)->selectRaw('COALESCE(SUM(line.debit), 0) AS debit, COALESCE(SUM(line.credit), 0) AS credit')->first();
        $direction = $account?->normal_balance === 'credit' ? -1 : 1;
        $query->select([
            'line.id', 'journals.posting_date', 'journals.id as journal_id', 'journals.number as journal_number',
            'line.account_id', 'accounts.code as account_code', 'accounts.name as account_name',
            'journals.reference_type', 'journals.reference', 'journals.source',
            DB::raw('COALESCE(line.description, journals.description) as description'), 'line.debit', 'line.credit',
        ])->selectRaw('SUM((line.debit - line.credit) * ?) OVER (ORDER BY journals.posting_date, journals.id, line.id) AS running_movement', [$direction]);

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 50);
        $entries = $query->orderBy('journals.posting_date')->orderBy('journals.id')->orderBy('line.id')->forPage($page, $perPage)->get()->map(function ($line) use ($companyId, $openingBalance): array {
            return [
                'id' => (string) $line->id, 'company_id' => $companyId, 'posting_date' => $line->posting_date,
                'journal_id' => (string) $line->journal_id, 'journal_number' => $line->journal_number,
                'account_id' => (string) $line->account_id, 'account_code' => $line->account_code, 'account_name' => $line->account_name,
                'reference_type' => $line->reference_type, 'reference' => $line->reference ?? '', 'source' => $line->source,
                'description' => $line->description ?? '', 'debit' => (int) $line->debit, 'credit' => (int) $line->credit,
                'running_balance' => $openingBalance + (int) $line->running_movement,
            ];
        })->all();
        $movement = $account?->movementBalance((int) $totals->debit, (int) $totals->credit) ?? ((int) $totals->debit - (int) $totals->credit);

        return ['opening_balance' => $openingBalance, 'closing_balance' => $openingBalance + $movement, 'total_debit' => (int) $totals->debit, 'total_credit' => (int) $totals->credit, 'entries' => $entries, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]];
    }

    /** @return array<string, mixed> */
    public function trialBalance(string $companyId, ?string $from = null, ?string $to = null): array
    {
        $accounts = Account::query()->where('company_id', $companyId)->orderBy('code')->get();
        $movements = $this->accountMovements($companyId, $from, $to);
        $depths = $this->depths($accounts);
        $rows = $accounts->map(function (Account $account) use ($movements, $depths): array {
            $movement = $movements->get($account->id);
            $opening = $account->opening_balance + $account->movementBalance((int) ($movement->opening_debit ?? 0), (int) ($movement->opening_credit ?? 0));
            $debit = (int) ($movement->debit ?? 0);
            $credit = (int) ($movement->credit ?? 0);
            $closing = $opening + $account->movementBalance($debit, $credit);
            $openingDebit = $account->normal_balance === 'debit' ? max($opening, 0) : max(-$opening, 0);
            $openingCredit = $account->normal_balance === 'credit' ? max($opening, 0) : max(-$opening, 0);
            $closingDebit = $account->normal_balance === 'debit' ? max($closing, 0) : max(-$closing, 0);
            $closingCredit = $account->normal_balance === 'credit' ? max($closing, 0) : max(-$closing, 0);

            return ['account_id' => $account->id, 'account_code' => $account->code, 'account_name' => $account->name, 'type' => $account->type, 'subtype' => $account->subtype, 'parent_id' => $account->parent_id, 'depth' => $depths[$account->id] ?? 0, 'normal_balance' => $account->normal_balance, 'opening_balance' => $opening, 'opening_debit' => $openingDebit, 'opening_credit' => $openingCredit, 'debit' => $debit, 'credit' => $credit, 'closing_balance' => $closing, 'closing_debit' => $closingDebit, 'closing_credit' => $closingCredit];
        });
        $debit = $rows->sum('closing_debit');
        $credit = $rows->sum('closing_credit');

        return ['from' => $from, 'to' => $to, 'opening_debit' => $rows->sum('opening_debit'), 'opening_credit' => $rows->sum('opening_credit'), 'movement_debit' => $rows->sum('debit'), 'movement_credit' => $rows->sum('credit'), 'debit' => $debit, 'credit' => $credit, 'balanced' => $debit === $credit, 'rows' => $rows->values()->all()];
    }

    /** @return array<string, mixed> */
    public function profitAndLoss(string $companyId, ?string $from, ?string $to): array
    {
        $query = $this->postedLines($companyId, true)->whereIn('accounts.type', ['revenue', 'expense']);
        if ($from !== null) {
            $query->whereDate('journals.posting_date', '>=', $from);
        }
        if ($to !== null) {
            $query->whereDate('journals.posting_date', '<=', $to);
        }
        $rows = $query->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type', 'accounts.subtype')->orderBy('accounts.code')->get(['accounts.id as account_id', 'accounts.code', 'accounts.name', 'accounts.type', 'accounts.subtype', DB::raw('SUM(line.debit) as debit'), DB::raw('SUM(line.credit) as credit')])->map(fn ($row): array => ['account_id' => $row->account_id, 'account_code' => $row->code, 'account_name' => $row->name, 'type' => $row->type, 'subtype' => $row->subtype, 'amount' => $row->type === 'revenue' ? (int) $row->credit - (int) $row->debit : (int) $row->debit - (int) $row->credit]);
        $revenue = $rows->where('type', 'revenue')->whereNotIn('subtype', ['other_income'])->sum('amount');
        $otherIncome = $rows->where('subtype', 'other_income')->sum('amount');
        $costOfSales = $rows->where('subtype', 'cost_of_sales')->sum('amount');
        $operatingExpenses = $rows->where('type', 'expense')->whereNotIn('subtype', ['cost_of_sales', 'other_expense'])->sum('amount');
        $otherExpenses = $rows->where('subtype', 'other_expense')->sum('amount');
        $grossProfit = $revenue - $costOfSales;

        return ['from' => $from, 'to' => $to, 'revenue' => $revenue, 'cost_of_sales' => $costOfSales, 'gross_profit' => $grossProfit, 'operating_expenses' => $operatingExpenses, 'other_income' => $otherIncome, 'other_expenses' => $otherExpenses, 'net_profit' => $grossProfit - $operatingExpenses + $otherIncome - $otherExpenses, 'rows' => $rows->values()->all(), 'trend' => $this->profitAndLossTrend($companyId, $to)];
    }

    /** @return array<string, mixed> */
    public function balanceSheet(string $companyId, string $asOf): array
    {
        $accounts = Account::query()->where('company_id', $companyId)->whereIn('type', ['asset', 'liability', 'equity'])->orderBy('code')->get();
        $movements = $this->accountMovements($companyId, null, $asOf);
        $depths = $this->depths($accounts);
        $rows = $accounts->map(function (Account $account) use ($movements, $depths): array {
            $movement = $movements->get($account->id);
            $balance = $account->opening_balance + $account->movementBalance((int) ($movement->debit ?? 0), (int) ($movement->credit ?? 0));

            return ['account_id' => $account->id, 'account_code' => $account->code, 'account_name' => $account->name, 'type' => $account->type, 'subtype' => $account->subtype, 'parent_id' => $account->parent_id, 'depth' => $depths[$account->id] ?? 0, 'balance' => $balance];
        });
        $assets = $rows->where('type', 'asset')->sum('balance');
        $liabilities = $rows->where('type', 'liability')->sum('balance');
        $equity = $rows->where('type', 'equity')->sum('balance');
        $lastClose = Journal::query()->where('company_id', $companyId)->where('source', 'year_end_close')->where('status', 'posted')->whereDate('posting_date', '<=', $asOf)->latest('posting_date')->first();
        $earningsFrom = $lastClose?->posting_date->addDay()->format('Y-m-d');
        $currentEarnings = $this->profitAndLoss($companyId, $earningsFrom, $asOf)['net_profit'];
        $equityWithEarnings = $equity + $currentEarnings;

        return ['as_of' => $asOf, 'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity, 'current_earnings' => $currentEarnings, 'equity_including_current_earnings' => $equityWithEarnings, 'balanced' => $assets === $liabilities + $equityWithEarnings, 'difference' => $assets - $liabilities - $equityWithEarnings, 'rows' => $rows->values()->all()];
    }

    /** @return Collection<string, object> */
    public function profitAndLossActuals(string $companyId, string $from, string $to): Collection
    {
        return $this->postedLines($companyId, true)
            ->whereIn('accounts.type', ['revenue', 'expense'])
            ->whereDate('journals.posting_date', '>=', $from)
            ->whereDate('journals.posting_date', '<=', $to)
            ->groupBy('line.account_id')
            ->selectRaw("line.account_id, SUM(CASE WHEN accounts.type = 'revenue' THEN line.credit - line.debit ELSE line.debit - line.credit END) AS amount")
            ->get()
            ->keyBy('account_id');
    }

    /** @return array<string, mixed> */
    public function comparativeProfitAndLoss(string $companyId, string $from, string $to, string $comparisonFrom, string $comparisonTo): array
    {
        return [
            'current' => $this->profitAndLoss($companyId, $from, $to),
            'comparison' => $this->profitAndLoss($companyId, $comparisonFrom, $comparisonTo),
            'ranges' => ['current' => ['from' => $from, 'to' => $to], 'comparison' => ['from' => $comparisonFrom, 'to' => $comparisonTo]],
        ];
    }

    private function postedLines(string $companyId, bool $excludeYearEndClose = false): Builder
    {
        $query = DB::table('journal_lines as line')->join('journals', 'journals.id', '=', 'line.journal_id')->join('accounts', 'accounts.id', '=', 'line.account_id')->where('journals.company_id', $companyId)->whereIn('journals.status', ['posted', 'reversed']);
        if ($excludeYearEndClose) {
            $query->where('journals.source', '!=', 'year_end_close')->whereNotExists(fn (Builder $subquery) => $subquery->selectRaw('1')->from('journals as closing_original')->whereColumn('closing_original.id', 'journals.reverses_journal_id')->where('closing_original.source', 'year_end_close'));
        }

        return $query;
    }

    /** @return array<int, array{month:string, actual:int, comparison:int}> */
    private function profitAndLossTrend(string $companyId, ?string $to): array
    {
        $end = CarbonImmutable::parse($to ?? now()->toDateString())->endOfMonth();
        $firstMonth = $end->startOfMonth()->subMonths(5);
        $lines = $this->postedLines($companyId, true)
            ->whereIn('accounts.type', ['revenue', 'expense'])
            ->whereDate('journals.posting_date', '>=', $firstMonth->subYear()->toDateString())
            ->whereDate('journals.posting_date', '<=', $end->toDateString())
            ->get(['journals.posting_date', 'accounts.type', 'line.debit', 'line.credit']);
        $monthly = $lines->groupBy(fn ($line): string => mb_substr($line->posting_date, 0, 7))->map(fn (Collection $monthLines): int => $monthLines->sum(fn ($line): int => $line->type === 'revenue' ? (int) $line->credit - (int) $line->debit : (int) $line->credit - (int) $line->debit));

        return collect(range(0, 5))->map(function (int $offset) use ($firstMonth, $monthly): array {
            $month = $firstMonth->addMonths($offset);

            return ['month' => $month->format('M'), 'actual' => (int) $monthly->get($month->format('Y-m'), 0), 'comparison' => (int) $monthly->get($month->subYear()->format('Y-m'), 0)];
        })->all();
    }

    /** @param array<string, mixed> $filters */
    private function applyLedgerFilters(Builder $query, array $filters): void
    {
        if (isset($filters['account_id'])) {
            $query->where('line.account_id', $filters['account_id']);
        }
        if (isset($filters['from'])) {
            $query->whereDate('journals.posting_date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('journals.posting_date', '<=', $filters['to']);
        }
        if (isset($filters['reference_type'])) {
            $query->where('journals.reference_type', $filters['reference_type']);
        }
        if (isset($filters['source'])) {
            $query->where('journals.source', $filters['source']);
        }
        if (isset($filters['reference'])) {
            $query->where('journals.reference', 'like', '%'.$filters['reference'].'%');
        }
        if (isset($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn (Builder $builder) => $builder->where('journals.number', 'like', $search)->orWhere('journals.reference', 'like', $search)->orWhere('journals.description', 'like', $search)->orWhere('line.description', 'like', $search)->orWhere('accounts.name', 'like', $search)->orWhere('accounts.code', 'like', $search));
        }
    }

    /** @return Collection<string, object> */
    private function accountMovements(string $companyId, ?string $from, ?string $to): Collection
    {
        $query = $this->postedLines($companyId)->groupBy('line.account_id');
        if ($to !== null) {
            $query->whereDate('journals.posting_date', '<=', $to);
        }
        if ($from !== null) {
            $query->selectRaw('line.account_id, SUM(CASE WHEN journals.posting_date < ? THEN line.debit ELSE 0 END) AS opening_debit, SUM(CASE WHEN journals.posting_date < ? THEN line.credit ELSE 0 END) AS opening_credit, SUM(CASE WHEN journals.posting_date >= ? THEN line.debit ELSE 0 END) AS debit, SUM(CASE WHEN journals.posting_date >= ? THEN line.credit ELSE 0 END) AS credit', [$from, $from, $from, $from]);
        } else {
            $query->selectRaw('line.account_id, 0 AS opening_debit, 0 AS opening_credit, SUM(line.debit) AS debit, SUM(line.credit) AS credit');
        }

        return $query->get()->keyBy('account_id');
    }

    /** @param Collection<int, Account> $accounts @return array<string, int> */
    private function depths(Collection $accounts): array
    {
        $byId = $accounts->keyBy('id');
        $depths = [];
        foreach ($accounts as $account) {
            $depth = 0;
            $parentId = $account->parent_id;
            $visited = [];
            while ($parentId !== null && $byId->has($parentId) && ! isset($visited[$parentId])) {
                $visited[$parentId] = true;
                $depth++;
                $parentId = $byId->get($parentId)->parent_id;
            }
            $depths[$account->id] = $depth;
        }

        return $depths;
    }
}
