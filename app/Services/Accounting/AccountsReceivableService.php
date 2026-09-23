<?php

namespace App\Services\Accounting;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AccountsReceivableService
{
    /** @return array<int, array<string, int|string>> */
    public function aging(string $companyId, ?string $customerId, string $asOf): array
    {
        $asOfDate = CarbonImmutable::parse($asOf)->startOfDay();
        $query = Invoice::query()->where('company_id', $companyId)->whereDate('invoice_date', '<=', $asOf)->whereNotNull('journal_id')->where('status', '!=', InvoiceStatus::Draft->value)->with('customer')->withSum(['payments as paid_as_of' => fn ($payments) => $payments->whereDate('payment_date', '<=', $asOf)], 'amount');
        if ($customerId !== null) {
            $query->where('customer_id', $customerId);
        }
        $rows = [];
        foreach ($query->get() as $invoice) {
            if ($invoice->voided_at !== null && $invoice->voided_at->toDateString() <= $asOf) {
                continue;
            }
            $paid = (int) ($invoice->paid_as_of ?? 0);
            $outstanding = max(0, $invoice->total - $paid);
            if ($outstanding === 0) {
                continue;
            }
            $days = $invoice->due_date->diffInDays($asOfDate, false);
            $bucket = match (true) {
                $days <= 0 => 'current', $days <= 30 => 'd1_30', $days <= 60 => 'd31_60', $days <= 90 => 'd61_90', default => 'd90_plus',
            };
            $row = $rows[$invoice->customer_id] ?? ['party_id' => $invoice->customer_id, 'party_name' => $invoice->customer->name, 'current' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90_plus' => 0, 'total' => 0];
            $row[$bucket] += $outstanding;
            $row['total'] += $outstanding;
            $rows[$invoice->customer_id] = $row;
        }

        return collect($rows)->sortByDesc('total')->values()->all();
    }

    /** @return array{party_id:string,party_name:string,from:string,to:string,opening_balance:int,closing_balance:int,lines:array<int,array<string,int|string>>} */
    public function statement(string $companyId, Customer $customer, string $from, string $to): array
    {
        $events = $this->events($companyId, $customer->id, $to);
        $opening = $events->filter(fn (array $event): bool => $event['date'] < $from)->sum(fn (array $event): int => $event['debit'] - $event['credit']);
        $balance = $opening;
        $lines = $events->filter(fn (array $event): bool => $event['date'] >= $from)->map(function (array $event) use (&$balance): array {
            $balance += $event['debit'] - $event['credit'];

            return [...$event, 'balance' => $balance];
        })->values()->all();

        return ['party_id' => $customer->id, 'party_name' => $customer->name, 'from' => $from, 'to' => $to, 'opening_balance' => $opening, 'closing_balance' => $balance, 'lines' => $lines];
    }

    /** @return Collection<int, array<string, int|string>> */
    private function events(string $companyId, string $customerId, string $to): Collection
    {
        $events = collect();
        $invoices = Invoice::query()->where('company_id', $companyId)->where('customer_id', $customerId)->whereNotNull('journal_id')->whereDate('invoice_date', '<=', $to)->with('reversalJournal:id,posting_date')->get();
        foreach ($invoices as $invoice) {
            $events->push(['id' => $invoice->id, 'date' => $invoice->invoice_date->format('Y-m-d'), 'type' => 'invoice', 'reference' => $invoice->invoice_number, 'description' => 'Sales invoice', 'debit' => $invoice->total, 'credit' => 0]);
            $reversalDate = $invoice->reversalJournal?->posting_date?->toDateString();
            if ($reversalDate !== null && $reversalDate <= $to) {
                $events->push(['id' => "{$invoice->id}-void", 'date' => $reversalDate, 'type' => 'credit_note', 'reference' => $invoice->invoice_number, 'description' => 'Invoice void and accounting reversal', 'debit' => 0, 'credit' => $invoice->total]);
            }
        }
        CustomerPayment::query()->where('company_id', $companyId)->where('customer_id', $customerId)->whereDate('payment_date', '<=', $to)->with('invoice:id,invoice_number')->get()->each(function (CustomerPayment $payment) use ($events): void {
            $events->push(['id' => $payment->id, 'date' => $payment->payment_date->format('Y-m-d'), 'type' => 'payment', 'reference' => $payment->number, 'description' => "Receipt against {$payment->invoice->invoice_number}", 'debit' => 0, 'credit' => $payment->amount]);
        });

        return $events->sortBy([['date', 'asc'], ['type', 'asc'], ['id', 'asc']])->values();
    }
}
