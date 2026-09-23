<?php

namespace App\Services\Banking;

use App\Models\BankStatementImport;
use App\Models\BankTransaction;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\SupplierBill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankingReportingService
{
    public function bookBalance(FinancialAccount $account, ?string $asOf = null): int
    {
        $query = DB::table('journal_lines')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.company_id', $account->company_id)->where('journal_lines.account_id', $account->gl_account_id)
            ->whereIn('journals.status', ['posted', 'reversed']);
        if ($asOf !== null) {
            $query->whereDate('journals.posting_date', '<=', $asOf);
        }
        $movement = $query->selectRaw('COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) AS balance')->value('balance');

        return (int) ($account->glAccount->opening_balance ?? 0) + (int) $movement;
    }

    /** @return array<string, mixed> */
    public function currentCash(string $companyId, ?string $asOf = null): array
    {
        $accounts = FinancialAccount::query()->where('company_id', $companyId)->where('is_active', true)->with('glAccount')->orderBy('name')->get();
        $rows = $accounts->map(function (FinancialAccount $account) use ($asOf): array {
            return ['id' => $account->id, 'name' => $account->name, 'type' => $account->type->value, 'currency' => $account->currency, 'gl_account_id' => $account->gl_account_id, 'book_balance' => $this->bookBalance($account, $asOf), 'statement_balance' => $this->statementBalance($account, $asOf)];
        });

        $totalsByCurrency = $rows->groupBy('currency')->map(fn ($currencyRows): int => (int) $currencyRows->sum('book_balance'));

        return ['as_of' => $asOf ?? now()->toDateString(), 'currency' => $totalsByCurrency->count() === 1 ? $totalsByCurrency->keys()->first() : null, 'total' => $totalsByCurrency->count() === 1 ? $totalsByCurrency->values()->first() : null, 'totals_by_currency' => $totalsByCurrency->all(), 'accounts' => $rows->all()];
    }

    /** @return array<string, mixed> */
    public function forecast(string $companyId, int $horizon = 30, ?string $asOf = null): array
    {
        $horizon = in_array($horizon, [7, 30, 60, 90], true) ? $horizon : 30;
        $start = CarbonImmutable::parse($asOf ?? now()->toDateString())->startOfDay();
        $end = $start->addDays($horizon);
        $invoices = Invoice::query()->where('company_id', $companyId)->whereIn('status', ['unpaid', 'partial'])->where('balance_due', '>', 0)->whereDate('due_date', '<=', $end->toDateString())->with('customer:id,name')->orderBy('due_date')->get();
        $bills = SupplierBill::query()->where('company_id', $companyId)->whereIn('status', ['unpaid', 'partial'])->where('balance_due', '>', 0)->whereDate('due_date', '<=', $end->toDateString())->with('supplier:id,name')->orderBy('due_date')->get();
        $current = $this->currentCash($companyId, $start->toDateString());
        if ($current['total'] === null) {
            throw ValidationException::withMessages(['currency' => 'A consolidated cash forecast requires one account currency. Full FX forecasting is deferred.']);
        }
        $inflows = (int) $invoices->sum('balance_due');
        $outflows = (int) $bills->sum('balance_due');

        return ['as_of' => $start->toDateString(), 'horizon_days' => $horizon, 'through' => $end->toDateString(), 'current_cash' => $current['total'], 'expected_inflows' => $inflows, 'expected_outflows' => $outflows, 'projected_cash' => $current['total'] + $inflows - $outflows, 'accounts' => $current['accounts'], 'inflows' => $invoices->map(fn (Invoice $invoice): array => ['id' => $invoice->id, 'number' => $invoice->invoice_number, 'party' => $invoice->customer->name, 'due_date' => $invoice->due_date->toDateString(), 'amount' => $invoice->balance_due])->all(), 'outflows' => $bills->map(fn (SupplierBill $bill): array => ['id' => $bill->id, 'number' => $bill->bill_number, 'party' => $bill->supplier->name, 'due_date' => $bill->due_date->toDateString(), 'amount' => $bill->balance_due])->all()];
    }

    /** @return array<string, mixed> */
    public function cashMovement(string $companyId, string $from, string $to): array
    {
        $accountIds = FinancialAccount::query()->where('company_id', $companyId)->pluck('gl_account_id');
        $rows = DB::table('journal_lines')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journals.company_id', $companyId)->whereIn('journal_lines.account_id', $accountIds)->whereIn('journals.status', ['posted', 'reversed'])
            ->whereDate('journals.posting_date', '>=', $from)->whereDate('journals.posting_date', '<=', $to)->orderBy('journals.posting_date')
            ->get(['journals.id', 'journals.number', 'journals.posting_date', 'journals.source', 'journals.reference', 'journals.description', 'accounts.id as account_id', 'accounts.name as account_name', 'journal_lines.debit', 'journal_lines.credit'])
            ->map(fn (object $row): array => ['journal_id' => $row->id, 'journal_number' => $row->number, 'date' => $row->posting_date, 'source' => $row->source, 'reference' => $row->reference, 'description' => $row->description, 'account_id' => $row->account_id, 'account_name' => $row->account_name, 'inflow' => (int) $row->debit, 'outflow' => (int) $row->credit, 'net' => (int) $row->debit - (int) $row->credit]);

        return ['from' => $from, 'to' => $to, 'inflows' => (int) $rows->sum('inflow'), 'outflows' => (int) $rows->sum('outflow'), 'net_movement' => (int) $rows->sum('net'), 'rows' => $rows->all()];
    }

    /** @return array<string, mixed> */
    public function bankGl(FinancialAccount $account, ?string $asOf = null): array
    {
        $book = $this->bookBalance($account, $asOf);
        $statement = $this->statementBalance($account, $asOf);
        $query = BankTransaction::query()->where('company_id', $account->company_id)->where('financial_account_id', $account->id)->whereIn('status', ['unmatched', 'suggested', 'partially_matched']);
        if ($asOf !== null) {
            $query->whereDate('transaction_date', '<=', $asOf);
        }
        $transactions = $query->get(['direction', 'amount']);
        $credits = (int) $transactions->filter(fn (BankTransaction $transaction): bool => $transaction->direction->value === 'credit')->sum('amount');
        $debits = (int) $transactions->filter(fn (BankTransaction $transaction): bool => $transaction->direction->value === 'debit')->sum('amount');

        return ['financial_account_id' => $account->id, 'as_of' => $asOf ?? now()->toDateString(), 'statement_balance' => $statement, 'book_balance' => $book, 'unmatched_credits' => $credits, 'unmatched_debits' => $debits, 'adjusted_statement_balance' => $statement === null ? null : $statement - $credits + $debits, 'difference' => $statement === null ? null : $statement - $book, 'status' => $statement !== null && $statement === $book ? 'balanced' : 'unbalanced'];
    }

    private function statementBalance(FinancialAccount $account, ?string $asOf): ?int
    {
        $transaction = BankTransaction::query()->where('company_id', $account->company_id)->where('financial_account_id', $account->id)->whereNotNull('running_balance')->when($asOf !== null, fn ($query) => $query->whereDate('transaction_date', '<=', $asOf))->orderByDesc('transaction_date')->orderByDesc('statement_row')->first();
        if ($transaction !== null) {
            return $transaction->running_balance;
        }

        $balance = BankStatementImport::query()->where('company_id', $account->company_id)->where('financial_account_id', $account->id)->where('status', 'confirmed')->whereNotNull('closing_balance')->when($asOf !== null, fn ($query) => $query->whereDate('statement_end_date', '<=', $asOf))->orderByDesc('statement_end_date')->value('closing_balance');

        return $balance === null ? null : (int) $balance;
    }
}
