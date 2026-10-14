<?php

namespace App\Services\Fbr;

use App\Models\Company;
use App\Models\PakistanFbrInvoice;
use App\Models\User;
use App\Services\Accounting\InvoiceCalculationService;
use App\Services\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PakistanFbrInvoiceService
{
    private const MAX_MONEY = 9007199254740991;

    public function __construct(private readonly InvoiceCalculationService $calculator, private readonly AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function create(string $companyId, User $user, array $data, string $idempotencyKey): PakistanFbrInvoice
    {
        return DB::transaction(function () use ($companyId, $user, $data, $idempotencyKey): PakistanFbrInvoice {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = PakistanFbrInvoice::query()->where('company_id', $companyId)->where('creation_idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->document_state === 'DELETED') {
                    throw new ConflictHttpException('This creation idempotency key belongs to a deleted FBR Invoice.');
                }
                if (! hash_equals($existing->creation_idempotency_hash, $hash)) {
                    throw new ConflictHttpException('The creation idempotency key belongs to a different FBR Invoice payload.');
                }

                return $existing->load('lines');
            }
            $sequence = (int) PakistanFbrInvoice::query()->where('company_id', $companyId)->where('is_historical', false)->max('sequence');
            do {
                $sequence++;
                $number = 'PKF-'.str_pad((string) $sequence, 8, '0', STR_PAD_LEFT);
            } while (PakistanFbrInvoice::query()->where('company_id', $companyId)->where('invoice_number', $number)->exists());
            [$header, $lines] = $this->calculate($data);
            $invoice = PakistanFbrInvoice::query()->create([
                ...$header, 'company_id' => $companyId, 'created_by' => $user->id, 'sequence' => $sequence,
                'invoice_number' => $number, 'document_state' => 'DRAFT', 'is_historical' => false,
                'creation_idempotency_key' => $idempotencyKey, 'creation_idempotency_hash' => $hash,
            ]);
            $invoice->lines()->createMany($lines);
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_draft_created', 'pakistan_fbr', $invoice, null, ['invoice_number' => $number, 'total' => $invoice->total]);

            return $invoice->load('lines');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(string $companyId, User $user, PakistanFbrInvoice $invoice, array $data): PakistanFbrInvoice
    {
        return DB::transaction(function () use ($companyId, $user, $invoice, $data): PakistanFbrInvoice {
            $invoice = PakistanFbrInvoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id);
            if (! $invoice->isEditable()) {
                throw ValidationException::withMessages(['invoice' => 'Historical, in-flight, uncertain, submitted or accepted FBR Invoices are immutable.']);
            }
            [$header, $lines] = $this->calculate($data);
            $before = $invoice->only(['invoice_number', 'total', 'document_state']);
            $invoice->update([...$header, 'updated_by' => $user->id]);
            $invoice->lines()->delete();
            $invoice->lines()->createMany($lines);
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_draft_updated', 'pakistan_fbr', $invoice, $before, $invoice->only(['invoice_number', 'total', 'document_state']));

            return $invoice->load('lines');
        });
    }

    public function deleteDraft(string $companyId, User $user, PakistanFbrInvoice $invoice): void
    {
        DB::transaction(function () use ($companyId, $user, $invoice): void {
            $document = PakistanFbrInvoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id);
            if (! $document->isDeletableDraft()) {
                throw new ConflictHttpException('Only an unsubmitted FBR Invoice draft with no submission attempts can be deleted.');
            }
            $document->update(['document_state' => 'DELETED', 'updated_by' => $user->id]);
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_draft_deleted', 'pakistan_fbr', $document, ['document_state' => 'DRAFT'], ['document_state' => 'DELETED']);
        });
    }

    /** @param array<string, mixed> $data @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>} */
    private function calculate(array $data): array
    {
        $input = array_map(fn (array $line): array => [...$line, 'sales_type' => $data['sale_type']], $data['lines']);
        $calculationInput = array_map(function (array $line): array {
            if ((int) ($line['taxable_amount'] ?? 0) > 0) {
                $line['unit_price'] = 0;
                $line['discount'] = 0;
            }
            if ((int) ($line['sales_tax'] ?? 0) > 0) {
                $line['tax_rate_bps'] = 0;
            }

            return $line;
        }, $input);
        $calculated = $this->calculator->calculate($calculationInput);
        $lines = [];
        $totals = array_fill_keys(['subtotal', 'discount', 'taxable_amount', 'sales_tax', 'other_tax', 'advance_tax', 'withholding_tax', 'total'], 0);
        foreach ($calculated['lines'] as $index => $line) {
            $explicitTaxable = (int) ($input[$index]['taxable_amount'] ?? 0);
            if ($explicitTaxable > 0) {
                $line['unit_price'] = (int) $input[$index]['unit_price'];
                $line['discount'] = (int) ($input[$index]['discount'] ?? 0);
                $line['subtotal'] = $this->checkedSum([$explicitTaxable, $line['discount']], "lines.$index.taxable_amount");
                $line['taxable_amount'] = $explicitTaxable;
                foreach (['tax_amount' => 'tax_rate_bps', 'other_tax_amount' => 'other_tax_rate_bps', 'advance_tax_amount' => 'advance_tax_rate_bps', 'withholding_tax_amount' => 'withholding_tax_rate_bps'] as $amountField => $rateField) {
                    $line[$amountField] = $this->rateAmount($explicitTaxable, (int) ($input[$index][$rateField] ?? 0), "lines.$index.$rateField");
                }
            }
            $explicitSalesTax = (int) ($input[$index]['sales_tax'] ?? 0);
            if ($explicitSalesTax > 0) {
                $line['tax_amount'] = $explicitSalesTax;
                $line['tax_rate_bps'] = (int) $input[$index]['tax_rate_bps'];
            }
            foreach (['extra_tax' => 'other_tax_amount', 'further_tax' => 'advance_tax_amount', 'st_withheld' => 'withholding_tax_amount'] as $field => $lineField) {
                if (! array_key_exists($field, $input[$index])) {
                    continue;
                }
                if ((int) ($input[$index][match ($field) {
                    'extra_tax' => 'other_tax_rate_bps', 'further_tax' => 'advance_tax_rate_bps', default => 'withholding_tax_rate_bps',
                }] ?? 0) !== 0) {
                    throw ValidationException::withMessages(["lines.$index.$field" => 'An explicit tax amount cannot also specify its rate.']);
                }
                $line[$lineField] = (int) $input[$index][$field];
            }
            $line['total'] = $this->checkedSum([
                $line['taxable_amount'], $line['tax_amount'], $line['other_tax_amount'], $line['advance_tax_amount'],
            ], "lines.$index");
            foreach (['subtotal' => 'subtotal', 'discount' => 'discount', 'taxable_amount' => 'taxable_amount', 'sales_tax' => 'tax_amount', 'other_tax' => 'other_tax_amount', 'advance_tax' => 'advance_tax_amount', 'withholding_tax' => 'withholding_tax_amount', 'total' => 'total'] as $headerField => $lineField) {
                $totals[$headerField] = $this->checkedSum([$totals[$headerField], $line[$lineField]], "lines.$index.$headerField");
            }
            $lines[] = [
                ...Arr::except($line, ['item_id', 'item_name', 'tax_metadata']),
                ...Arr::only($input[$index], ['hs_code', 'fbr_rate_id', 'sro_schedule_id', 'sro_item_id']),
            ];
        }
        $header = [
            ...Arr::only($data, ['customer_id', 'invoice_date', 'due_date', 'invoice_type', 'sale_type', 'origin_province', 'destination_province', 'buyer_snapshot', 'notes']),
            ...$totals, 'currency' => 'PKR', 'due_date' => $data['due_date'] ?? $data['invoice_date'],
        ];

        return [$header, $lines];
    }

    private function rateAmount(int $amount, int $basisPoints, string $field): int
    {
        $whole = intdiv($amount, 10000) * $basisPoints;
        $remainder = intdiv((($amount % 10000) * $basisPoints) + 5000, 10000);

        return $this->checkedSum([$whole, $remainder], $field);
    }

    /** @param array<int, int> $values */
    private function checkedSum(array $values, string $field): int
    {
        $sum = 0;
        foreach ($values as $value) {
            if (($value > 0 && $sum > self::MAX_MONEY - $value) || ($value < 0 && $sum < -self::MAX_MONEY - $value)) {
                throw ValidationException::withMessages([$field => 'The calculated amount exceeds the supported minor-unit range.']);
            }
            $sum += $value;
        }

        return $sum;
    }
}
