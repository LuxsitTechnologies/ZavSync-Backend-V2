<?php

namespace App\Services\Migration;

use App\Enums\FbrSubmissionStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FbrReferenceValue;
use App\Models\LegacyEntityMap;
use App\Models\LegacyFbrEvidence;
use App\Models\LegacyImportRun;
use App\Models\MigrationException;
use App\Models\PakistanFbrInvoice;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class LegacyInvoiceImportService
{
    public function __construct(
        private readonly ExactDecimalConverter $decimals,
        private readonly HistoricalResponseSanitizer $responseSanitizer,
        private readonly LegacyReconciliationService $reconciliation,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    public function report(array $snapshot, Company $company, string $sourceCompanyId, string $fingerprint): array
    {
        $this->validateSnapshot($snapshot);
        $snapshot['manifest'] = $this->safeManifest($snapshot['manifest']);
        $run = LegacyImportRun::query()->where('company_id', $company->id)->where('source_company_id', $sourceCompanyId)
            ->where('source_system', $this->sourceSystem($snapshot))->where('source_fingerprint', $fingerprint)->firstOrFail();

        return [
            'mode' => 'RECONCILE_ONLY', 'writes_performed' => false, 'run_id' => $run->id,
            ...$this->reconciliation->reconcile($run, $this->sourceRows($snapshot, $sourceCompanyId)),
            'exceptions' => $run->exceptions()->get()->map(fn (MigrationException $exception): array => $exception->only(['source_entity_type', 'source_id', 'target_id', 'exception_code', 'severity', 'safe_metadata', 'resolution_state']))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function execute(array $snapshot, Company $company, User $actor, string $sourceCompanyId, string $fingerprint, string $filename, bool $dryRun = false, ?string $resumeRunId = null): array
    {
        $this->validateSnapshot($snapshot);
        $snapshot['manifest'] = $this->safeManifest($snapshot['manifest']);
        $sourceSystem = $this->sourceSystem($snapshot);
        $source = $this->sourceRows($snapshot, $sourceCompanyId);
        $analysis = $this->analyze($source, $company);
        if ($dryRun) {
            return ['mode' => 'DRY_RUN', 'writes_performed' => false, 'source_system' => $sourceSystem, 'source_company_id' => $sourceCompanyId, 'company_mapping' => ['source_id' => $sourceCompanyId, 'target_id' => $company->id], ...$analysis];
        }

        $run = $this->run($company, $actor, $sourceSystem, $fingerprint, $filename, $snapshot, $sourceCompanyId, $resumeRunId);
        $this->audit->recordOperation($actor, $company->id, 'legacy_invoice_import_started', 'migration', $run, null, ['source_system' => $sourceSystem, 'mode' => 'IMPORT']);
        try {
            $this->mapCompany($run, $company, $sourceCompanyId);
            if ($source['invoices'] === []) {
                $this->exception($run, $company->id, 'company', $sourceCompanyId, null, 'MISSING_COMPANY_MAPPING', ['reason' => 'no_source_invoices_for_company']);
            }
            collect($source['invoices'])->countBy(fn (array $invoice): string => (string) ($invoice['id'] ?? ''))->filter(fn (int $count): bool => $count > 1)->each(function (int $count, string $sourceId) use ($run, $company): void {
                $this->exception($run, $company->id, 'invoice', $sourceId, null, 'DUPLICATE_SOURCE_ID', ['occurrences' => $count]);
            });
            $processed = 0;
            $chunks = array_chunk($source['invoices'], max(1, (int) config('legacy_migration.chunk_size', 100)));
            foreach ($chunks as $chunk) {
                DB::transaction(function () use ($chunk, $source, $run, $company, $actor, $sourceSystem, &$processed): void {
                    Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                    foreach ($chunk as $row) {
                        $this->importInvoice($row, $source, $run, $company, $actor, $sourceSystem);
                        $processed++;
                    }
                    $run->update(['progress' => ['invoices_processed' => $processed, 'invoices_total' => count($source['invoices'])]]);
                });
            }
            $report = $this->reconciliation->reconcile($run, $source);
            $run->update(['status' => 'COMPLETED', 'completed_at' => now(), 'reconciliation' => $report, 'failure_message' => null]);
            $this->audit->recordOperation($actor, $company->id, 'legacy_invoice_import_completed', 'migration', $run, null, ['source_counts' => $report['source_counts'], 'target_counts' => $report['target_counts'], 'open_exception_count' => $report['open_exception_count']]);

            return ['mode' => 'IMPORT', 'writes_performed' => true, 'run_id' => $run->id, ...$report];
        } catch (Throwable $exception) {
            $run->update(['status' => 'FAILED', 'failure_message' => 'Import failed. Review the exception ledger and correlated application error.']);
            $this->audit->recordOperation($actor, $company->id, 'legacy_invoice_import_failed', 'migration', $run, null, ['status' => 'FAILED']);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function validateSnapshot(array $snapshot): void
    {
        Validator::make($snapshot, [
            'source_system' => ['required', 'string', 'max:60'], 'manifest' => ['present', 'array'],
            'invoices' => ['present', 'array'], 'invoice_items' => ['present', 'array'], 'fbr_invoice_submissions' => ['present', 'array'],
            'invoices.*' => ['required', 'array'], 'invoice_items.*' => ['required', 'array'], 'fbr_invoice_submissions.*' => ['required', 'array'],
            'invoices.*.id' => ['required', 'regex:/^[0-9]{1,20}$/'],
            'invoices.*.company_id' => ['required', 'regex:/^[0-9]{1,20}$/'],
            'invoices.*.invoice_number' => ['required', 'string', 'max:120'],
            'invoices.*.issue_date' => ['required', 'date_format:Y-m-d'],
            'invoices.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'invoices.*.status' => ['required', 'string', 'max:40'],
            'invoices.*.buyer_name' => ['required', 'string', 'max:255'],
            'invoices.*.buyer_registration_no' => ['nullable', 'string', 'max:30'],
            'invoices.*.buyer_type' => ['nullable', 'string', 'max:60'],
            'invoices.*.buyer_address' => ['nullable', 'string', 'max:2000'],
            'invoices.*.sale_origination_province' => ['nullable', 'string', 'max:100'],
            'invoices.*.destination_of_supply' => ['nullable', 'string', 'max:100'],
            'invoices.*.fbr_invoice_type' => ['nullable', 'string', 'max:120'],
            'invoices.*.fbr_sale_type' => ['nullable', 'string', 'max:120'],
            'invoices.*.notes' => ['nullable', 'string', 'max:5000'],
            'invoices.*.created_at' => ['required', 'date'], 'invoices.*.updated_at' => ['required', 'date'],
            'invoice_items.*.id' => ['required', 'regex:/^[0-9]{1,20}$/', 'distinct'],
            'invoice_items.*.invoice_id' => ['required', 'regex:/^[0-9]{1,20}$/'],
            'invoice_items.*.description' => ['required', 'string', 'max:2000'],
            'invoice_items.*.uom' => ['required', 'string', 'max:60'],
            'invoice_items.*.hs_code' => ['nullable', 'string', 'max:60'],
            'invoice_items.*.created_at' => ['required', 'date'], 'invoice_items.*.updated_at' => ['required', 'date'],
            'fbr_invoice_submissions.*.id' => ['required', 'regex:/^[0-9]{1,20}$/', 'distinct'],
            'fbr_invoice_submissions.*.invoice_id' => ['required', 'regex:/^[0-9]{1,20}$/', 'distinct'],
            'fbr_invoice_submissions.*.status' => ['required', 'string', 'max:40'],
            'fbr_invoice_submissions.*.fbr_invoice_number' => ['nullable', 'string', 'max:255'],
            'fbr_invoice_submissions.*.retry_count' => ['required', 'integer', 'between:0,1000000'],
            'fbr_invoice_submissions.*.last_attempt_at' => ['nullable', 'date'],
            'fbr_invoice_submissions.*.created_at' => ['required', 'date'], 'fbr_invoice_submissions.*.updated_at' => ['required', 'date'],
        ])->validate();
        foreach (collect($snapshot['invoices'])->groupBy('id') as $rows) {
            if ($rows->pluck('company_id')->unique()->count() > 1) {
                throw ValidationException::withMessages(['CROSS_TENANT_REFERENCE' => 'A source invoice ID belongs to multiple companies.']);
            }
        }
        $ids = collect($snapshot['invoices'])->pluck('id')->map(fn ($id): string => (string) $id)->all();
        foreach ([...$snapshot['invoice_items'], ...$snapshot['fbr_invoice_submissions']] as $row) {
            if (! in_array((string) $row['invoice_id'], $ids, true)) {
                throw ValidationException::withMessages(['SOURCE_ORPHAN_REFERENCE' => 'A source line or submission has no invoice in the supplied export.']);
            }
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function sourceSystem(array $snapshot): string
    {
        $sourceSystem = $snapshot['source_system'] ?? null;
        if (! is_string($sourceSystem) || ! preg_match('/^[A-Za-z0-9_-]{1,60}$/', $sourceSystem)) {
            throw ValidationException::withMessages(['source_system' => 'The source system identifier is invalid.']);
        }

        return $sourceSystem;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{manifest:array<string,mixed>,invoices:array<int,array<string,mixed>>,items_by_invoice:array<string,array<int,array<string,mixed>>>,submissions_by_invoice:array<string,array<string,mixed>>}
     */
    private function sourceRows(array $snapshot, string $sourceCompanyId): array
    {
        $invoices = collect($snapshot['invoices'])->filter(fn (mixed $row): bool => is_array($row) && (string) ($row['company_id'] ?? '') === $sourceCompanyId)->values()->all();
        $invoiceIds = collect($invoices)->map(fn (array $row): string => (string) ($row['id'] ?? ''))->filter()->all();
        $items = collect($snapshot['invoice_items'])->filter(fn (mixed $row): bool => is_array($row) && in_array((string) ($row['invoice_id'] ?? ''), $invoiceIds, true))->groupBy(fn (array $row): string => (string) $row['invoice_id'])->map(fn ($rows): array => $rows->values()->all())->all();
        $submissions = collect($snapshot['fbr_invoice_submissions'])->filter(fn (mixed $row): bool => is_array($row) && in_array((string) ($row['invoice_id'] ?? ''), $invoiceIds, true))->keyBy(fn (array $row): string => (string) $row['invoice_id'])->all();

        return ['manifest' => is_array($snapshot['manifest']) ? $snapshot['manifest'] : [], 'invoices' => $invoices, 'items_by_invoice' => $items, 'submissions_by_invoice' => $submissions];
    }

    /**
     * @param  array{manifest:array<string,mixed>,invoices:array<int,array<string,mixed>>,items_by_invoice:array<string,array<int,array<string,mixed>>>,submissions_by_invoice:array<string,array<string,mixed>>}  $source
     * @return array<string, mixed>
     */
    private function analyze(array $source, Company $company): array
    {
        $exceptions = [];
        $converted = 0;
        $totals = ['subtotal' => 0, 'total_tax' => 0, 'total_discount' => 0, 'total_amount' => 0, 'amount_paid' => 0];
        $mappings = [];
        foreach ($source['invoices'] as $invoice) {
            $id = (string) ($invoice['id'] ?? '');
            try {
                $header = $this->convertHeader($invoice);
                $lines = array_map(fn (array $line): array => $this->convertLine($line, $invoice), $source['items_by_invoice'][$id] ?? []);
                foreach (array_keys($totals) as $field) {
                    $totals[$field] = $this->decimals->sum([$totals[$field], $this->decimals->money($invoice[$field] ?? null)]);
                }
                $comparisons = [
                    'FINANCIAL_SUBTOTAL_MISMATCH' => [$header['subtotal'], $this->decimals->sum(array_column($lines, 'taxable_amount'))],
                    'FINANCIAL_TAX_MISMATCH' => [$header['sales_tax'], $this->decimals->sum([
                        $this->decimals->sum(array_column($lines, 'tax_amount')),
                        $this->decimals->sum(array_column($lines, 'other_tax_amount')),
                        $this->decimals->sum(array_column($lines, 'advance_tax_amount')),
                        -$this->decimals->sum(array_column($lines, 'withholding_tax_amount')),
                    ])],
                    'FINANCIAL_TOTAL_MISMATCH' => [$header['total'], $this->decimals->sum(array_column($lines, 'total'))],
                ];
                foreach ($comparisons as $code => [$stored, $lineTotal]) {
                    if ($stored !== $lineTotal) {
                        $exceptions[] = ['source_id' => $id, 'exception_code' => $code, 'header_minor' => $stored, 'line_minor' => $lineTotal];
                    }
                }
                foreach ($this->unknownReferences($lines) as $unknown) {
                    $exceptions[] = ['source_id' => $id, 'exception_code' => 'UNKNOWN_REFERENCE_DATA', ...$unknown];
                }
                if ($lines === []) {
                    $exceptions[] = ['source_id' => $id, 'exception_code' => 'SOURCE_LINE_MISSING'];
                }
                [$customer, $ambiguous] = $this->matchCustomer($company->id, (string) ($invoice['buyer_registration_no'] ?? ''));
                $mappings[] = ['source_invoice_id' => $id, 'target_customer_id' => $customer?->id, 'state' => $ambiguous ? 'AMBIGUOUS' : ($customer === null ? 'SNAPSHOT_ONLY' : 'MATCHED')];
                if ($ambiguous) {
                    $exceptions[] = ['source_id' => $id, 'exception_code' => 'CUSTOMER_MAPPING_AMBIGUOUS'];
                }
                $fbr = $this->fbrState($source['submissions_by_invoice'][$id] ?? null);
                if ($fbr['requires_review']) {
                    $exceptions[] = ['source_id' => $id, 'exception_code' => 'AMBIGUOUS_FBR_SUCCESS'];
                }
                $converted++;
            } catch (InvalidArgumentException $exception) {
                $exceptions[] = ['source_id' => $id, 'exception_code' => 'UNSUPPORTED_DECIMAL_PRECISION', 'message' => $exception->getMessage()];
            }
        }
        foreach (['id' => 'DUPLICATE_SOURCE_ID', 'invoice_number' => 'DUPLICATE_INVOICE_NUMBER'] as $field => $code) {
            foreach (collect($source['invoices'])->countBy(fn (array $invoice): string => (string) ($invoice[$field] ?? '')) as $value => $count) {
                if ($count > 1) {
                    $exceptions[] = ['source_id' => (string) $value, 'exception_code' => $code, 'occurrences' => $count];
                }
            }
        }
        foreach (collect($source['submissions_by_invoice'])->filter(fn (array $row): bool => filled($row['fbr_invoice_number'] ?? null))->countBy('fbr_invoice_number') as $reference => $count) {
            if ($count > 1) {
                $exceptions[] = ['exception_code' => 'DUPLICATE_FBR_REFERENCE', 'reference' => $reference, 'occurrences' => $count];
            }
        }

        return [
            'source_counts' => ['invoices' => count($source['invoices']), 'invoice_lines' => collect($source['items_by_invoice'])->flatten(1)->count(), 'fbr_submissions' => count($source['submissions_by_invoice'])],
            'convertible_invoices' => $converted, 'financial_totals_minor' => $totals,
            'customer_mappings' => $mappings, 'exceptions' => $exceptions,
            'manifest' => $source['manifest'],
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function run(Company $company, User $actor, string $sourceSystem, string $fingerprint, string $filename, array $snapshot, string $sourceCompanyId, ?string $resumeRunId): LegacyImportRun
    {
        if ($resumeRunId !== null) {
            $run = LegacyImportRun::query()->where('company_id', $company->id)->findOrFail($resumeRunId);
            if ($run->source_company_id !== $sourceCompanyId || $run->source_system !== $sourceSystem || ! hash_equals($run->source_fingerprint, $fingerprint) || ! in_array($run->status, ['FAILED', 'RUNNING'], true)) {
                throw ValidationException::withMessages(['resume' => 'The import run cannot be resumed with this source.']);
            }
            $run->update(['status' => 'RUNNING', 'failure_message' => null]);

            return $run;
        }

        $existing = LegacyImportRun::query()->where('company_id', $company->id)->where('source_system', $sourceSystem)->where('source_fingerprint', $fingerprint)->first();
        if ($existing !== null) {
            if ($existing->source_company_id !== $sourceCompanyId) {
                throw ValidationException::withMessages(['CROSS_TENANT_REFERENCE' => 'The import run belongs to a different source company.']);
            }
            if ($existing->status === 'COMPLETED') {
                return $existing;
            }
            throw ValidationException::withMessages(['source' => 'This source is already assigned to an import run. Resume that run explicitly.']);
        }

        return LegacyImportRun::query()->create([
            'company_id' => $company->id, 'source_system' => $sourceSystem, 'source_fingerprint' => $fingerprint,
            'source_company_id' => $sourceCompanyId,
            'status' => 'RUNNING', 'mode' => 'IMPORT', 'source_filename' => $filename,
            'source_manifest' => Arr::only($snapshot['manifest'], ['counts', 'invoice_statuses', 'fbr_statuses', 'financial_totals', 'mismatch_counts']),
            'progress' => ['invoices_processed' => 0, 'invoices_total' => 0], 'created_by' => $actor->id, 'started_at' => now(),
        ]);
    }

    private function mapCompany(LegacyImportRun $run, Company $company, string $sourceCompanyId): void
    {
        $conflict = LegacyEntityMap::query()->where('source_system', $run->source_system)->where('source_entity_type', 'company')
            ->where(function ($query) use ($company, $sourceCompanyId): void {
                $query->where(fn ($query) => $query->where('source_id', $sourceCompanyId)->where('company_id', '!=', $company->id))
                    ->orWhere(fn ($query) => $query->where('company_id', $company->id)->where('source_id', '!=', $sourceCompanyId));
            })->exists();
        if ($conflict) {
            $this->exception($run, $company->id, 'company', $sourceCompanyId, null, 'CROSS_TENANT_REFERENCE', ['reason' => 'company_mapping_conflict']);
            throw ValidationException::withMessages(['company' => 'The source company already has a different tenant mapping.']);
        }
        LegacyEntityMap::query()->firstOrCreate([
            'company_id' => $company->id, 'source_system' => $run->source_system, 'source_entity_type' => 'company', 'source_id' => $sourceCompanyId,
        ], ['import_run_id' => $run->id, 'target_entity_type' => Company::class, 'target_id' => $company->id]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{manifest:array<string,mixed>,invoices:array<int,array<string,mixed>>,items_by_invoice:array<string,array<int,array<string,mixed>>>,submissions_by_invoice:array<string,array<string,mixed>>}  $source
     */
    private function importInvoice(array $row, array $source, LegacyImportRun $run, Company $company, User $actor, string $sourceSystem): void
    {
        $sourceId = (string) ($row['id'] ?? '');
        if ($sourceId === '') {
            $this->exception($run, $company->id, 'invoice', null, null, 'DUPLICATE_SOURCE_ID', ['reason' => 'missing_source_id']);

            return;
        }
        $existingMap = LegacyEntityMap::query()->where('company_id', $company->id)->where('source_system', $sourceSystem)->where('source_entity_type', 'invoice')->where('source_id', $sourceId)->first();
        $sourceHash = hash('sha256', json_encode([$row, $source['items_by_invoice'][$sourceId] ?? [], $source['submissions_by_invoice'][$sourceId] ?? null], JSON_THROW_ON_ERROR));
        if ($existingMap !== null) {
            if (($existingMap->safe_metadata['source_hash'] ?? null) !== $sourceHash) {
                $this->exception($run, $company->id, 'invoice', $sourceId, $existingMap->target_id, 'SOURCE_RECORD_CHANGED', ['reason' => 'source_differs_from_immutable_import']);
            }

            return;
        }
        $invoiceNumber = trim((string) ($row['invoice_number'] ?? ''));
        if ($invoiceNumber === '' || PakistanFbrInvoice::query()->where('company_id', $company->id)->where('invoice_number', $invoiceNumber)->exists()) {
            $this->exception($run, $company->id, 'invoice', $sourceId, null, 'DUPLICATE_INVOICE_NUMBER', ['invoice_number' => $invoiceNumber]);

            return;
        }

        try {
            $financials = $this->convertHeader($row);
            $sourceLines = $source['items_by_invoice'][$sourceId] ?? [];
            if ($sourceLines === []) {
                $this->exception($run, $company->id, 'invoice', $sourceId, null, 'SOURCE_LINE_MISSING', []);

                return;
            }
            $lines = array_map(function (array $line, int $position) use ($row): array {
                return [...$this->convertLine($line, $row), 'position' => $position + 1];
            }, $sourceLines, array_keys($sourceLines));
        } catch (InvalidArgumentException $exception) {
            $this->exception($run, $company->id, 'invoice', $sourceId, null, 'UNSUPPORTED_DECIMAL_PRECISION', ['message' => $exception->getMessage()]);

            return;
        }

        [$customer, $customerAmbiguous] = $this->matchCustomer($company->id, (string) ($row['buyer_registration_no'] ?? ''));
        $taxIdentifier = preg_replace('/\D+/', '', (string) ($row['buyer_registration_no'] ?? '')) ?? '';
        if ($taxIdentifier !== '' && ! in_array(mb_strlen($taxIdentifier), [7, 13], true)) {
            $this->exception($run, $company->id, 'invoice', $sourceId, null, 'INVALID_TAX_IDENTIFIER', ['buyer_registration_number' => (string) ($row['buyer_registration_no'] ?? '')], 'WARNING');
        }
        if ($customerAmbiguous) {
            $this->exception($run, $company->id, 'invoice', $sourceId, null, 'CUSTOMER_MAPPING_AMBIGUOUS', ['buyer_registration_no' => (string) ($row['buyer_registration_no'] ?? '')]);
        }
        $submission = $source['submissions_by_invoice'][$sourceId] ?? null;
        $fbr = $this->fbrState($submission);
        $sequence = null;
        $invoice = PakistanFbrInvoice::query()->create([
            'company_id' => $company->id, 'is_historical' => true, 'legacy_import_run_id' => $run->id,
            'legacy_source_system' => $sourceSystem, 'legacy_source_id' => $sourceId, 'legacy_original_company_id' => (string) ($row['company_id'] ?? ''),
            'legacy_status' => (string) ($row['status'] ?? ''), 'historical_accounting_state' => 'DOCUMENT_ONLY_UNPOSTED', 'migration_reconciliation_state' => 'PENDING',
            'buyer_snapshot' => ['registration_number' => $row['buyer_registration_no'] ?? null, 'name' => $row['buyer_name'] ?? null, 'type' => $row['buyer_type'] ?? null, 'address' => $row['buyer_address'] ?? null, 'origin_province' => $row['sale_origination_province'] ?? null, 'destination_province' => $row['destination_of_supply'] ?? null],
            'legacy_original_timestamps' => ['created_at' => $row['created_at'] ?? null, 'updated_at' => $row['updated_at'] ?? null],
            'legacy_original_financial_values' => Arr::only($row, ['subtotal', 'total_tax', 'total_discount', 'total_amount', 'amount_paid', 'tax_rate', 'fbr_invoice_type', 'fbr_sale_type']),
            'customer_id' => $customer?->id, 'sequence' => $sequence, 'invoice_number' => $invoiceNumber,
            'invoice_date' => $row['issue_date'], 'due_date' => $row['due_date'] ?? $row['issue_date'], 'invoice_type' => $row['fbr_invoice_type'] ?? null, 'sale_type' => $row['fbr_sale_type'] ?? null, 'origin_province' => $row['sale_origination_province'] ?? null, 'destination_province' => $row['destination_of_supply'] ?? null,
            'currency' => 'PKR', 'document_state' => 'HISTORICAL',
            ...$financials, 'notes' => $row['notes'] ?? null,
            'fbr_status' => $fbr['status'], 'fbr_reference_number' => $fbr['reference'], 'fbr_response_metadata' => $submission === null ? null : ['historical_evidence' => true, 'requires_review' => $fbr['requires_review']],
            'created_by' => $actor->id,
        ]);
        $invoice->lines()->createMany($lines);
        $unknown = $this->unknownReferences($lines);
        if ($unknown !== []) {
            $this->exception($run, $company->id, 'invoice', $sourceId, $invoice->id, 'UNKNOWN_REFERENCE_DATA', ['references' => $unknown], 'WARNING');
        }
        LegacyEntityMap::query()->create(['import_run_id' => $run->id, 'company_id' => $company->id, 'source_system' => $sourceSystem, 'source_entity_type' => 'invoice', 'source_id' => $sourceId, 'target_entity_type' => PakistanFbrInvoice::class, 'target_id' => $invoice->id, 'safe_metadata' => ['invoice_number' => $invoiceNumber, 'source_hash' => $sourceHash]]);
        foreach ($sourceLines as $position => $sourceLine) {
            LegacyEntityMap::query()->firstOrCreate(['company_id' => $company->id, 'source_system' => $sourceSystem, 'source_entity_type' => 'invoice_line', 'source_id' => (string) ($sourceLine['id'] ?? "{$sourceId}:{$position}")], ['import_run_id' => $run->id, 'target_entity_type' => get_class($invoice->lines[$position]), 'target_id' => $invoice->lines[$position]->id]);
        }
        $this->financialExceptions($run, $invoice, $lines);
        if ($submission !== null) {
            $this->importEvidence($submission, $fbr, $run, $invoice, $sourceSystem);
        }
        $invoice->update(['migration_reconciliation_state' => MigrationException::query()->where('import_run_id', $run->id)->where('target_id', $invoice->id)->exists() ? 'PRESERVED_WITH_EXCEPTIONS' : 'RECONCILED']);
    }

    /** @param array<string, mixed> $row @return array<string, int> */
    private function convertHeader(array $row): array
    {
        $subtotal = $this->decimals->money($row['subtotal'] ?? null);
        $discount = $this->decimals->money($row['total_discount'] ?? null);
        $salesTax = $this->decimals->money($row['total_tax'] ?? null);
        $total = $this->decimals->money($row['total_amount'] ?? null);
        $amountPaid = $this->decimals->money($row['amount_paid'] ?? null);

        return ['subtotal' => $subtotal, 'discount' => $discount, 'taxable_amount' => max(0, $subtotal - $discount), 'sales_tax' => $salesTax, 'other_tax' => 0, 'advance_tax' => 0, 'withholding_tax' => 0, 'total' => $total, 'amount_paid' => $amountPaid];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $invoice @return array<string, mixed> */
    private function convertLine(array $row, array $invoice): array
    {
        $value = $this->decimals->money($row['value_excl_st'] ?? null);
        $salesTax = $this->decimals->money($row['sales_tax'] ?? null);
        $extraTax = $this->decimals->money($row['extra_tax'] ?? '0');
        $furtherTax = $this->decimals->money($row['further_tax'] ?? '0');
        $withheld = $this->decimals->money($row['st_withheld'] ?? '0');

        return [
            'legacy_source_id' => isset($row['id']) ? (string) $row['id'] : null, 'position' => 1,
            'description' => (string) ($row['description'] ?? 'Historical invoice item'),
            'hs_code' => $row['hs_code'] ?? null, 'quantity_milli' => $this->decimals->quantity($row['quantity'] ?? null), 'unit' => (string) ($row['uom'] ?? 'unit'),
            'unit_price' => $this->decimals->money($row['rate'] ?? null), 'subtotal' => $value, 'discount' => 0, 'taxable_amount' => $value,
            'tax_rate_bps' => $this->decimals->percentageToBasisPoints($invoice['tax_rate'] ?? '0'), 'fbr_rate_id' => isset($row['fbr_rate_id']) ? (string) $row['fbr_rate_id'] : null,
            'tax_amount' => $salesTax, 'other_tax_rate_bps' => 0, 'other_tax_amount' => $extraTax,
            'advance_tax_rate_bps' => 0, 'advance_tax_amount' => $furtherTax, 'withholding_tax_rate_bps' => 0, 'withholding_tax_amount' => $withheld,
            'sro_schedule_id' => isset($row['sro_schedule_id']) ? (string) $row['sro_schedule_id'] : null, 'sro_item_id' => isset($row['sro_item_id']) ? (string) $row['sro_item_id'] : null,
            'total' => $this->decimals->money($row['amount'] ?? null), 'sales_type' => (string) ($invoice['fbr_sale_type'] ?? 'Historical'),
            'legacy_original_values' => Arr::only($row, ['quantity', 'rate', 'value_excl_st', 'sales_tax', 'extra_tax', 'further_tax', 'st_withheld', 'amount', 'created_at', 'updated_at', 'service_id']),
        ];
    }

    /** @return array{0:?Customer,1:bool} */
    private function matchCustomer(string $companyId, string $registration): array
    {
        $registration = preg_replace('/\D+/', '', $registration) ?? '';
        if ($registration === '') {
            return [null, false];
        }
        $matches = Customer::query()->where('company_id', $companyId)->where(function ($query) use ($registration): void {
            $query->where('ntn', $registration)->orWhere('cnic', $registration)->orWhere('strn', $registration);
        })->limit(2)->get();

        return [$matches->count() === 1 ? $matches->first() : null, $matches->count() > 1];
    }

    /** @param array<string, mixed>|null $submission @return array{status:FbrSubmissionStatus,reference:?string,requires_review:bool} */
    private function fbrState(?array $submission): array
    {
        if ($submission === null) {
            return ['status' => FbrSubmissionStatus::NotSubmitted, 'reference' => null, 'requires_review' => false];
        }
        $successful = mb_strtolower((string) ($submission['status'] ?? '')) === 'success';
        $reference = trim((string) ($submission['fbr_invoice_number'] ?? '')) ?: null;

        return ['status' => $successful ? ($reference === null ? FbrSubmissionStatus::Submitted : FbrSubmissionStatus::Accepted) : FbrSubmissionStatus::Failed, 'reference' => $reference, 'requires_review' => $successful && $reference === null];
    }

    /** @param array<string, mixed> $submission @param array{status:FbrSubmissionStatus,reference:?string,requires_review:bool} $fbr */
    private function importEvidence(array $submission, array $fbr, LegacyImportRun $run, PakistanFbrInvoice $invoice, string $sourceSystem): void
    {
        $sourceId = (string) ($submission['id'] ?? '');
        if ($fbr['reference'] !== null && LegacyFbrEvidence::query()->where('company_id', $invoice->company_id)->where('fbr_reference_number', $fbr['reference'])->exists()) {
            $this->exception($run, $invoice->company_id, 'fbr_submission', $sourceId, $invoice->id, 'DUPLICATE_FBR_REFERENCE', ['fbr_reference_number' => $fbr['reference']]);
            $fbr['requires_review'] = true;
        }
        $evidence = LegacyFbrEvidence::query()->create([
            'company_id' => $invoice->company_id, 'invoice_id' => $invoice->id, 'import_run_id' => $run->id, 'source_system' => $sourceSystem,
            'source_id' => $sourceId, 'original_status' => (string) ($submission['status'] ?? ''), 'normalized_status' => $fbr['status']->value,
            'fbr_reference_number' => $fbr['reference'], 'retry_count' => (int) ($submission['retry_count'] ?? 0), 'last_attempt_at' => $submission['last_attempt_at'] ?? null,
            'source_created_at' => $submission['created_at'] ?? null, 'source_updated_at' => $submission['updated_at'] ?? null,
            'original_timestamps' => Arr::only($submission, ['created_at', 'updated_at', 'last_attempt_at']),
            'sanitized_response' => $this->responseSanitizer->sanitize($submission['api_response'] ?? null), 'requires_review' => $fbr['requires_review'], 'submission_blocked' => true,
        ]);
        LegacyEntityMap::query()->create(['import_run_id' => $run->id, 'company_id' => $invoice->company_id, 'source_system' => $sourceSystem, 'source_entity_type' => 'fbr_submission', 'source_id' => $sourceId, 'target_entity_type' => LegacyFbrEvidence::class, 'target_id' => $evidence->id]);
        if ($fbr['requires_review'] && $fbr['reference'] === null) {
            $this->exception($run, $invoice->company_id, 'fbr_submission', $sourceId, $invoice->id, 'AMBIGUOUS_FBR_SUCCESS', ['original_status' => $submission['status'] ?? null]);
        }
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function financialExceptions(LegacyImportRun $run, PakistanFbrInvoice $invoice, array $lines): void
    {
        $comparisons = [
            'FINANCIAL_SUBTOTAL_MISMATCH' => [$invoice->subtotal, $this->decimals->sum(array_column($lines, 'taxable_amount'))],
            'FINANCIAL_TAX_MISMATCH' => [$invoice->sales_tax, $this->decimals->sum([$this->decimals->sum(array_column($lines, 'tax_amount')), $this->decimals->sum(array_column($lines, 'other_tax_amount')), $this->decimals->sum(array_column($lines, 'advance_tax_amount')), -$this->decimals->sum(array_column($lines, 'withholding_tax_amount'))])],
            'FINANCIAL_TOTAL_MISMATCH' => [$invoice->total, $this->decimals->sum(array_column($lines, 'total'))],
        ];
        foreach ($comparisons as $code => [$header, $line]) {
            if ($header !== $line) {
                $this->exception($run, $invoice->company_id, 'invoice', $invoice->legacy_source_id, $invoice->id, $code, ['header_minor' => $header, 'line_minor' => $line, 'difference_minor' => $header - $line], 'WARNING');
            }
        }
    }

    /** @param array<string, mixed> $metadata */
    private function exception(LegacyImportRun $run, ?string $companyId, string $entityType, ?string $sourceId, ?string $targetId, string $code, array $metadata, string $severity = 'ERROR'): void
    {
        MigrationException::query()->firstOrCreate(['import_run_id' => $run->id, 'company_id' => $companyId, 'source_entity_type' => $entityType, 'source_id' => $sourceId, 'target_id' => $targetId, 'exception_code' => $code], ['severity' => $severity, 'safe_metadata' => $metadata, 'resolution_state' => 'OPEN']);
    }

    /** @param array<int, array<string, mixed>> $lines @return array<int, array<string, mixed>> */
    private function unknownReferences(array $lines): array
    {
        $unknown = [];
        foreach (['hs_code' => 'HS_CODE', 'unit' => 'UOM', 'fbr_rate_id' => 'RATE', 'sales_type' => 'SALE_TYPE', 'sro_schedule_id' => 'SRO_SCHEDULE', 'sro_item_id' => 'SRO_ITEM'] as $field => $category) {
            $known = FbrReferenceValue::query()->where('category', $category)->where('is_active', true)->pluck('code')->all();
            foreach ($lines as $index => $line) {
                if (filled($line[$field] ?? null) && ! in_array($line[$field], $known, true)) {
                    $unknown[] = ['line_position' => $index + 1, 'category' => $category, 'code' => $line[$field], 'reason' => $known === [] ? 'reference_catalog_unconfigured' : 'unknown_reference'];
                }
            }
        }

        return $unknown;
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    private function safeManifest(array $manifest): array
    {
        $allowed = [
            'counts' => ['companies', 'users', 'clients', 'invoices', 'invoice_items', 'payments', 'fbr_invoice_submissions'],
            'invoice_statuses' => ['draft', 'sent'], 'fbr_statuses' => ['success', 'failed'],
            'mismatch_counts' => ['subtotal', 'tax', 'total'],
            'financial_totals' => ['subtotal', 'total_tax', 'total_discount', 'total_amount', 'amount_paid'],
        ];
        $safe = [];
        foreach ($allowed as $group => $keys) {
            foreach ($keys as $key) {
                $value = $manifest[$group][$key] ?? null;
                if (is_int($value) || (is_string($value) && preg_match('/^[0-9]+(?:\\.[0-9]+)?$/', $value))) {
                    $safe[$group][$key] = $value;
                }
            }
        }

        return $safe;
    }
}
