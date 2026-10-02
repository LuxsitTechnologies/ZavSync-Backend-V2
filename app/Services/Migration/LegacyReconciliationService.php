<?php

namespace App\Services\Migration;

use App\Models\LegacyEntityMap;
use App\Models\LegacyFbrEvidence;
use App\Models\LegacyImportRun;
use App\Models\MigrationException;
use App\Models\PakistanFbrInvoice;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class LegacyReconciliationService
{
    public function __construct(private readonly ExactDecimalConverter $decimals) {}

    /** @param array<string, mixed> $source @return array<string, mixed> */
    public function reconcile(LegacyImportRun $run, array $source): array
    {
        $sourceLines = collect($source['items_by_invoice'])->flatten(1)->all();
        $sourceSubmissions = array_values($source['submissions_by_invoice']);
        $ids = array_map(fn (array $row): string => (string) $row['id'], $source['invoices']);
        $invoices = PakistanFbrInvoice::query()->where('company_id', $run->company_id)->where('legacy_source_system', $run->source_system)
            ->whereIn('legacy_source_id', $ids)->with('lines')->get()->keyBy('legacy_source_id');
        $lines = $invoices->flatMap(fn (PakistanFbrInvoice $invoice) => $invoice->lines)->keyBy('legacy_source_id');
        $evidence = LegacyFbrEvidence::query()->where('company_id', $run->company_id)->where('source_system', $run->source_system)->whereIn('invoice_id', $invoices->pluck('id'))->get()->keyBy('source_id');
        $sourceCounts = ['invoices' => count($source['invoices']), 'invoice_lines' => count($sourceLines), 'fbr_submissions' => count($sourceSubmissions)];
        $targetCounts = ['invoices' => $invoices->count(), 'invoice_lines' => $lines->count(), 'fbr_submissions' => $evidence->count()];
        $unexplained = collect($sourceCounts)->sum(fn (int $count, string $key): int => abs($count - $targetCounts[$key]));
        $headerFields = ['subtotal' => 'subtotal', 'total_tax' => 'sales_tax', 'total_discount' => 'discount', 'total_amount' => 'total', 'amount_paid' => 'amount_paid'];
        $lineFields = ['value_excl_st' => 'taxable_amount', 'sales_tax' => 'tax_amount', 'extra_tax' => 'other_tax_amount', 'further_tax' => 'advance_tax_amount', 'st_withheld' => 'withholding_tax_amount', 'amount' => 'total'];
        $sourceTotals = $this->totals($source['invoices'], array_keys($headerFields));
        $targetTotals = [];
        foreach ($headerFields as $field => $column) {
            $targetTotals[$field] = $this->decimals->sum($invoices->pluck($column)->all());
        }
        $sourceLineTotals = $this->totals($sourceLines, array_keys($lineFields));
        $targetLineTotals = [];
        foreach ($lineFields as $field => $column) {
            $targetLineTotals[$field] = $this->decimals->sum($lines->pluck($column)->all());
        }
        $recordDifferences = [];
        $invoiceMaps = LegacyEntityMap::query()->where('company_id', $run->company_id)->where('source_system', $run->source_system)
            ->where('source_entity_type', 'invoice')->whereIn('source_id', $ids)->get()->keyBy('source_id');
        $timestampCoverage = 0;
        foreach ($source['invoices'] as $row) {
            $target = $invoices->get((string) $row['id']);
            if ($target === null) {
                continue;
            }
            $sourceId = (string) $row['id'];
            $hash = hash('sha256', json_encode([$row, $source['items_by_invoice'][$sourceId] ?? [], $source['submissions_by_invoice'][$sourceId] ?? null], JSON_THROW_ON_ERROR));
            if (($invoiceMaps->get($sourceId)?->safe_metadata['source_hash'] ?? null) !== $hash) {
                $recordDifferences[] = ['entity' => 'invoice', 'source_id' => $sourceId, 'field' => 'source_record'];
            }
            foreach ($headerFields as $field => $column) {
                try {
                    if ($this->decimals->money($row[$field]) !== $target->{$column}) {
                        $recordDifferences[] = ['entity' => 'invoice', 'source_id' => (string) $row['id'], 'field' => $field];
                    }
                } catch (InvalidArgumentException) {
                    $recordDifferences[] = ['entity' => 'invoice', 'source_id' => (string) $row['id'], 'field' => $field];
                }
            }
            if ($target->invoice_number !== $row['invoice_number'] || $target->legacy_status !== $row['status'] || $target->legacy_original_company_id !== (string) $row['company_id']) {
                $recordDifferences[] = ['entity' => 'invoice', 'source_id' => (string) $row['id'], 'field' => 'identity_or_status'];
            }
            if ($target->legacy_original_timestamps === Arr::only($row, ['created_at', 'updated_at'])) {
                $timestampCoverage++;
            } else {
                $recordDifferences[] = ['entity' => 'invoice', 'source_id' => (string) $row['id'], 'field' => 'timestamps'];
            }
        }
        foreach ($sourceLines as $row) {
            $target = $lines->get((string) $row['id']);
            if ($target === null) {
                continue;
            }
            $fields = [...$lineFields, 'rate' => 'unit_price', 'quantity' => 'quantity_milli'];
            foreach ($fields as $field => $column) {
                try {
                    $expected = $field === 'quantity' ? $this->decimals->quantity($row[$field]) : $this->decimals->money($row[$field] ?? '0');
                    if ($expected !== $target->{$column}) {
                        $recordDifferences[] = ['entity' => 'invoice_line', 'source_id' => (string) $row['id'], 'field' => $field];
                    }
                } catch (InvalidArgumentException) {
                    $recordDifferences[] = ['entity' => 'invoice_line', 'source_id' => (string) $row['id'], 'field' => $field];
                }
            }
            if (Arr::only($target->legacy_original_values, ['created_at', 'updated_at']) !== Arr::only($row, ['created_at', 'updated_at'])) {
                $recordDifferences[] = ['entity' => 'invoice_line', 'source_id' => (string) $row['id'], 'field' => 'timestamps'];
            }
        }
        foreach ($sourceSubmissions as $row) {
            $target = $evidence->get((string) $row['id']);
            if ($target === null) {
                continue;
            }
            $reference = (string) ($row['fbr_invoice_number'] ?? '');
            if ($target->original_status !== $row['status'] || $target->fbr_reference_number !== ($reference === '' ? null : $reference)
                || $target->original_timestamps !== Arr::only($row, ['created_at', 'updated_at', 'last_attempt_at'])) {
                $recordDifferences[] = ['entity' => 'fbr_submission', 'source_id' => (string) $row['id'], 'field' => 'evidence'];
            }
        }
        $crosswalk = [];
        foreach (['invoice' => $ids, 'invoice_line' => array_column($sourceLines, 'id'), 'fbr_submission' => array_column($sourceSubmissions, 'id')] as $type => $sourceIds) {
            $crosswalk[$type] = LegacyEntityMap::query()->where('company_id', $run->company_id)->where('source_system', $run->source_system)
                ->where('source_entity_type', $type)->whereIn('source_id', $sourceIds)->whereNotNull('target_id')->count();
        }
        $unexplained += count($recordDifferences);
        $unexplained += $sourceTotals === $targetTotals ? 0 : 1;
        $unexplained += $sourceLineTotals === $targetLineTotals ? 0 : 1;
        $completeCrosswalk = array_sum($crosswalk) === array_sum($sourceCounts);
        $exceptionQuery = MigrationException::query()->where('import_run_id', $run->id);

        return [
            'source_counts' => $sourceCounts, 'target_counts' => $targetCounts,
            'company_mapping_count' => LegacyEntityMap::query()->where('company_id', $run->company_id)->where('source_system', $run->source_system)->where('source_entity_type', 'company')->count(),
            'customer_mapping' => ['matched' => $invoices->whereNotNull('customer_id')->count(), 'snapshot_only' => $invoices->whereNull('customer_id')->count()],
            'crosswalk_counts' => $crosswalk, 'crosswalk_complete' => $completeCrosswalk,
            'open_exception_count' => (clone $exceptionQuery)->where('resolution_state', 'OPEN')->count(),
            'exception_counts' => (clone $exceptionQuery)->selectRaw('exception_code, count(*) as aggregate')->groupBy('exception_code')->pluck('aggregate', 'exception_code')->all(),
            'unexplained_discrepancy_count' => $unexplained, 'record_differences' => $recordDifferences,
            'financial_totals_minor' => ['source' => $sourceTotals, 'target' => $targetTotals],
            'line_financial_totals_minor' => ['source' => $sourceLineTotals, 'target' => $targetLineTotals],
            'invoice_statuses' => ['source' => collect($source['invoices'])->countBy('status')->all(), 'target' => $invoices->countBy('legacy_status')->all()],
            'fbr_statuses' => ['source' => collect($sourceSubmissions)->countBy('status')->all(), 'target' => $evidence->countBy('original_status')->all()],
            'fbr_reference_counts' => ['source' => collect($sourceSubmissions)->filter(fn ($row): bool => filled($row['fbr_invoice_number'] ?? null))->count(), 'target' => $evidence->whereNotNull('fbr_reference_number')->count()],
            'original_timestamp_coverage' => $timestampCoverage, 'manifest' => $source['manifest'],
            'out_of_scope_entities' => ['users', 'clients_master_import', 'payments', 'opening_balances'],
        ];
    }

    /** @param array<int, array<string,mixed>> $rows @param array<int,string> $fields @return array<string,int> */
    private function totals(array $rows, array $fields): array
    {
        $totals = array_fill_keys($fields, 0);
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                try {
                    $totals[$field] = $this->decimals->sum([$totals[$field], $this->decimals->money($row[$field] ?? '0')]);
                } catch (InvalidArgumentException) {
                    continue;
                }
            }
        }

        return $totals;
    }
}
