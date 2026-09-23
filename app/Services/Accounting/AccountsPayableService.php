<?php

namespace App\Services\Accounting;

use App\Enums\SupplierBillStatus;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AccountsPayableService
{
    /** @return array<int,array<string,int|string>> */
    public function aging(string $companyId, ?string $supplierId, string $asOf): array
    {
        $asOfDate = CarbonImmutable::parse($asOf)->startOfDay();
        $query = SupplierBill::query()->where('company_id', $companyId)->whereDate('posting_date', '<=', $asOf)->whereNotNull('journal_id')->where('status', '!=', SupplierBillStatus::Draft->value)->with(['supplier', 'reversalJournal:id,posting_date'])->withSum(['allocations as paid_as_of' => fn ($allocations) => $allocations->whereHas('payment', fn ($payments) => $payments->whereDate('posting_date', '<=', $asOf))], 'amount');
        if ($supplierId !== null) {
            $query->where('supplier_id', $supplierId);
        }
        $rows = [];
        foreach ($query->get() as $bill) {
            if ($bill->voided_at !== null && $bill->reversalJournal?->posting_date?->toDateString() <= $asOf) {
                continue;
            }
            $outstanding = max(0, $bill->total - (int) ($bill->paid_as_of ?? 0));
            if ($outstanding === 0) {
                continue;
            }
            $days = $bill->due_date->diffInDays($asOfDate, false);
            $bucket = match (true) {
                $days <= 0 => 'current', $days <= 30 => 'd1_30', $days <= 60 => 'd31_60', $days <= 90 => 'd61_90', default => 'd90_plus',
            };
            $row = $rows[$bill->supplier_id] ?? ['party_id' => $bill->supplier_id, 'party_name' => $bill->supplier->name, 'current' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90_plus' => 0, 'total' => 0];
            $row[$bucket] += $outstanding;
            $row['total'] += $outstanding;
            $rows[$bill->supplier_id] = $row;
        }

        return collect($rows)->sortByDesc('total')->values()->all();
    }

    /** @return array{party_id:string,party_name:string,from:string,to:string,opening_balance:int,closing_balance:int,lines:array<int,array<string,int|string>>} */
    public function statement(string $companyId, Supplier $supplier, string $from, string $to): array
    {
        $events = $this->events($companyId, $supplier->id, $to);
        $opening = $events->filter(fn (array $event): bool => $event['date'] < $from)->sum(fn (array $event): int => $event['credit'] - $event['debit']);
        $balance = $opening;
        $lines = $events->filter(fn (array $event): bool => $event['date'] >= $from)->map(function (array $event) use (&$balance): array {
            $balance += $event['credit'] - $event['debit'];

            return [...$event, 'balance' => $balance];
        })->values()->all();

        return ['party_id' => $supplier->id, 'party_name' => $supplier->name, 'from' => $from, 'to' => $to, 'opening_balance' => $opening, 'closing_balance' => $balance, 'lines' => $lines];
    }

    /** @return Collection<int,array<string,int|string>> */
    private function events(string $companyId, string $supplierId, string $to): Collection
    {
        $events = collect();
        SupplierBill::query()->where('company_id', $companyId)->where('supplier_id', $supplierId)->whereNotNull('journal_id')->whereDate('posting_date', '<=', $to)->with('reversalJournal:id,posting_date')->get()->each(function (SupplierBill $bill) use ($events, $to): void {
            $events->push(['id' => $bill->id, 'date' => $bill->posting_date->format('Y-m-d'), 'type' => 'bill', 'reference' => $bill->bill_number, 'description' => $bill->supplier_invoice_number, 'debit' => 0, 'credit' => $bill->total]);
            $reversalDate = $bill->reversalJournal?->posting_date?->toDateString();
            if ($reversalDate !== null && $reversalDate <= $to) {
                $events->push(['id' => "{$bill->id}-void", 'date' => $reversalDate, 'type' => 'bill_void', 'reference' => $bill->bill_number, 'description' => 'Bill void and accounting reversal', 'debit' => $bill->total, 'credit' => 0]);
            }
        });
        SupplierPayment::query()->where('company_id', $companyId)->where('supplier_id', $supplierId)->whereDate('posting_date', '<=', $to)->with('allocations.bill:id,bill_number')->get()->each(function (SupplierPayment $payment) use ($events): void {
            foreach ($payment->allocations as $allocation) {
                $events->push(['id' => $allocation->id, 'date' => $payment->posting_date->format('Y-m-d'), 'type' => 'payment', 'reference' => $payment->number, 'description' => "Payment against {$allocation->bill->bill_number}", 'debit' => $allocation->amount, 'credit' => 0]);
            }
        });

        return $events->sortBy([['date', 'asc'], ['type', 'asc'], ['id', 'asc']])->values();
    }
}
