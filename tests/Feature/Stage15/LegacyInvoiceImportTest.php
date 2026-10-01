<?php

namespace Tests\Feature\Stage15;

use App\Models\Customer;
use App\Models\FbrCompanyConfiguration;
use App\Models\FbrReferenceValue;
use App\Models\LegacyEntityMap;
use App\Models\LegacyFbrEvidence;
use App\Models\LegacyImportRun;
use App\Models\MigrationException;
use App\Models\PakistanFbrInvoice;
use App\Services\Migration\LegacyInvoiceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Concerns\BuildsLegacyInvoiceSnapshots;
use Tests\TestCase;

class LegacyInvoiceImportTest extends TestCase
{
    use BuildsLegacyInvoiceSnapshots, RefreshDatabase;

    public function test_import_preserves_historical_values_status_buyer_snapshot_customer_mapping_and_sanitized_fbr_evidence(): void
    {
        $context = $this->stage3AccountingContext();
        $context['customer']->update(['ntn' => '1234567']);

        $report = app(LegacyInvoiceImportService::class)->execute($this->legacySnapshot(), $context['company'], $context['user'], '4', hash('sha256', 'fixture-one'), 'fixture-one.json');

        $invoice = PakistanFbrInvoice::query()->with(['lines', 'legacyFbrEvidence'])->sole();
        $this->assertTrue($invoice->is_historical);
        $this->assertSame('draft', $invoice->legacy_status);
        $this->assertSame('DOCUMENT_ONLY_UNPOSTED', $invoice->historical_accounting_state);
        $this->assertSame($context['customer']->id, $invoice->customer_id);
        $this->assertSame('Historical Buyer 1', $invoice->buyer_snapshot['name']);
        $this->assertSame(10000, $invoice->subtotal);
        $this->assertSame(1800, $invoice->sales_tax);
        $this->assertSame(11800, $invoice->total);
        $this->assertSame('100.00', $invoice->legacy_original_financial_values['subtotal']);
        $this->assertSame(1000, $invoice->lines->sole()->quantity_milli);
        $this->assertSame('100.00', $invoice->lines->sole()->legacy_original_values['value_excl_st']);
        $this->assertSame(['status' => 'success', 'message' => 'Sanitized fixture'], $invoice->legacyFbrEvidence->sole()->sanitized_response);
        $this->assertSame(0, $report['unexplained_discrepancy_count']);
        $this->assertDatabaseCount('legacy_entity_maps', 4);
    }

    public function test_reimport_of_same_source_is_idempotent(): void
    {
        $context = $this->stage3AccountingContext();
        $snapshot = $this->legacySnapshot();
        $importer = app(LegacyInvoiceImportService::class);

        $first = $importer->execute($snapshot, $context['company'], $context['user'], '4', hash('sha256', 'same-source'), 'same.json');
        $second = $importer->execute($snapshot, $context['company'], $context['user'], '4', hash('sha256', 'same-source'), 'same.json');

        $this->assertSame($first['run_id'], $second['run_id']);
        $this->assertDatabaseCount('pakistan_fbr_invoices', 1);
        $this->assertDatabaseCount('pakistan_fbr_invoice_lines', 1);
        $this->assertDatabaseCount('legacy_fbr_evidence', 1);
    }

    public function test_known_production_manifest_fixture_reconciles_counts_and_records_all_known_exceptions(): void
    {
        $context = $this->stage3AccountingContext();
        $context['customer']->update(['ntn' => '1234567']);

        $report = app(LegacyInvoiceImportService::class)->execute($this->legacySnapshot(128), $context['company'], $context['user'], '4', hash('sha256', 'production-acceptance-fixture'), 'production-acceptance.json');

        $this->assertSame(['invoices' => 128, 'invoice_lines' => 128, 'fbr_submissions' => 121], $report['target_counts']);
        $this->assertSame(0, $report['unexplained_discrepancy_count']);
        $this->assertSame(45, PakistanFbrInvoice::query()->where('legacy_status', 'draft')->count());
        $this->assertSame(83, PakistanFbrInvoice::query()->where('legacy_status', 'sent')->count());
        $this->assertSame(112, LegacyFbrEvidence::query()->where('normalized_status', 'accepted')->count());
        $this->assertSame(3, LegacyFbrEvidence::query()->where('requires_review', true)->count());
        $this->assertSame(['42', '52', '53'], LegacyFbrEvidence::query()->where('requires_review', true)->orderBy('source_id')->pluck('source_id')->all());
        $this->assertSame(['51', '61', '62'], LegacyFbrEvidence::query()->where('requires_review', true)->with('invoice')->get()->pluck('invoice.legacy_source_id')->sort()->values()->all());
        $this->assertSame(6, LegacyFbrEvidence::query()->where('normalized_status', 'failed')->count());
        $this->assertSame(2, MigrationException::query()->where('exception_code', 'FINANCIAL_SUBTOTAL_MISMATCH')->count());
        $this->assertSame(1, MigrationException::query()->where('exception_code', 'FINANCIAL_TAX_MISMATCH')->count());
        $this->assertSame(2, MigrationException::query()->where('exception_code', 'FINANCIAL_TOTAL_MISMATCH')->count());
        $this->assertSame(3, MigrationException::query()->where('exception_code', 'AMBIGUOUS_FBR_SUCCESS')->count());
        $this->assertSame(38, PakistanFbrInvoice::query()->where('legacy_status', 'draft')->whereHas('legacyFbrEvidence', fn ($query) => $query->where('original_status', 'success'))->count());
        $this->assertSame(7, PakistanFbrInvoice::query()->where('legacy_status', 'draft')->doesntHave('legacyFbrEvidence')->count());
        $this->assertSame(77, PakistanFbrInvoice::query()->where('legacy_status', 'sent')->whereHas('legacyFbrEvidence', fn ($query) => $query->where('original_status', 'success'))->count());
        $this->assertSame(6, PakistanFbrInvoice::query()->where('legacy_status', 'sent')->whereHas('legacyFbrEvidence', fn ($query) => $query->where('original_status', 'failed'))->count());
    }

    public function test_unsupported_precision_creates_exception_without_silently_rounding(): void
    {
        $context = $this->stage3AccountingContext();
        $snapshot = $this->legacySnapshot();
        $snapshot['invoices'][0]['subtotal'] = '100.001';

        app(LegacyInvoiceImportService::class)->execute($snapshot, $context['company'], $context['user'], '4', hash('sha256', 'bad-precision'), 'bad-precision.json');

        $this->assertDatabaseCount('pakistan_fbr_invoices', 0);
        $this->assertDatabaseHas('migration_exceptions', ['exception_code' => 'UNSUPPORTED_DECIMAL_PRECISION', 'source_id' => '1']);
    }

    public function test_failed_run_can_resume_with_same_source_without_duplicates(): void
    {
        $context = $this->stage3AccountingContext();
        $fingerprint = hash('sha256', 'resume-source');
        $run = LegacyImportRun::factory()->for($context['company'])->create(['source_fingerprint' => $fingerprint, 'status' => 'FAILED', 'created_by' => $context['user']->id, 'progress' => ['invoices_processed' => 0, 'invoices_total' => 1]]);

        $report = app(LegacyInvoiceImportService::class)->execute($this->legacySnapshot(), $context['company'], $context['user'], '4', $fingerprint, 'resume.json', false, $run->id);

        $this->assertSame($run->id, $report['run_id']);
        $this->assertDatabaseCount('pakistan_fbr_invoices', 1);
        $this->assertDatabaseHas('legacy_import_runs', ['id' => $run->id, 'status' => 'COMPLETED']);
    }

    public function test_ambiguous_customer_mapping_and_duplicate_fbr_reference_are_explicit_exceptions_without_guessing(): void
    {
        $context = $this->stage3AccountingContext();
        $context['customer']->update(['ntn' => '1234567']);
        Customer::factory()->for($context['company'])->create(['ntn' => '1234567', 'created_by' => $context['user']->id]);
        $snapshot = $this->legacySnapshot(2);
        $snapshot['fbr_invoice_submissions'][1]['fbr_invoice_number'] = 'FBR-1';

        app(LegacyInvoiceImportService::class)->execute($snapshot, $context['company'], $context['user'], '4', hash('sha256', 'ambiguous-mappings'), 'ambiguous.json');

        $this->assertSame(2, PakistanFbrInvoice::query()->whereNull('customer_id')->count());
        $this->assertSame(2, MigrationException::query()->where('exception_code', 'CUSTOMER_MAPPING_AMBIGUOUS')->count());
        $this->assertSame(1, MigrationException::query()->where('exception_code', 'DUPLICATE_FBR_REFERENCE')->count());
        $this->assertSame(2, LegacyFbrEvidence::query()->where('fbr_reference_number', 'FBR-1')->count());
        $this->assertSame(1, LegacyFbrEvidence::query()->where('requires_review', true)->count());
    }

    public function test_migration_visibility_is_permission_and_tenant_scoped(): void
    {
        $owner = $this->stage3AccountingContext(['migration.view']);
        $report = app(LegacyInvoiceImportService::class)->execute($this->legacySnapshot(), $owner['company'], $owner['user'], '4', hash('sha256', 'tenant-scope'), 'tenant.json');
        $invoice = PakistanFbrInvoice::query()->sole();

        $this->getJson('/api/v1/pakistan-fbr/migrations', ['X-Company-Id' => $owner['company']->id])->assertOk()->assertJsonPath('0.id', $report['run_id']);
        $this->getJson("/api/v1/pakistan-fbr/historical-invoices/{$invoice->id}/evidence", ['X-Company-Id' => $owner['company']->id])->assertOk()->assertJsonCount(1);

        $outsider = $this->stage3AccountingContext(['migration.view']);
        Sanctum::actingAs($outsider['user']);
        $this->getJson("/api/v1/pakistan-fbr/migrations/{$report['run_id']}", ['X-Company-Id' => $outsider['company']->id])->assertNotFound();
        $this->getJson("/api/v1/pakistan-fbr/historical-invoices/{$invoice->id}/evidence", ['X-Company-Id' => $outsider['company']->id])->assertNotFound();

        $restricted = $this->stage3AccountingContext([]);
        $this->getJson('/api/v1/pakistan-fbr/migrations', ['X-Company-Id' => $restricted['company']->id])->assertForbidden();
    }

    public function test_stage_fifteen_factories_create_valid_records(): void
    {
        $run = LegacyImportRun::factory()->create();

        $this->assertModelExists($run);
        $this->assertModelExists(LegacyEntityMap::factory()->create());
        $this->assertModelExists(LegacyFbrEvidence::factory()->create());
        $this->assertModelExists(MigrationException::factory()->create());
        $this->assertModelExists(FbrCompanyConfiguration::factory()->create());
        $this->assertModelExists(FbrReferenceValue::factory()->create());
    }

    public function test_confirmed_historical_fbr_reference_is_immutable(): void
    {
        $context = $this->stage3AccountingContext();
        app(LegacyInvoiceImportService::class)->execute($this->legacySnapshot(), $context['company'], $context['user'], '4', hash('sha256', 'immutable-evidence'), 'immutable.json');
        $evidence = LegacyFbrEvidence::query()->sole();

        $this->expectException(LogicException::class);
        $evidence->update(['fbr_reference_number' => 'REPLACED']);
    }

    public function test_exception_resolution_requires_manage_permission_is_tenant_scoped_and_audited(): void
    {
        $context = $this->stage3AccountingContext(['migration.view', 'migration.manage']);
        $snapshot = $this->legacySnapshot();
        $snapshot['invoices'][0]['total_amount'] = '119.00';
        app(LegacyInvoiceImportService::class)->execute($snapshot, $context['company'], $context['user'], '4', hash('sha256', 'resolve-exception'), 'resolve.json');
        $exception = MigrationException::query()->where('exception_code', 'FINANCIAL_TOTAL_MISMATCH')->sole();

        $this->patchJson("/api/v1/pakistan-fbr/migration-exceptions/{$exception->id}", ['resolution_state' => 'RESOLVED', 'resolution_note' => 'Verified against the source ledger.'], ['X-Company-Id' => $context['company']->id])
            ->assertOk()->assertJsonPath('resolution_state', 'RESOLVED');

        $this->assertDatabaseHas('migration_exceptions', ['id' => $exception->id, 'resolved_by' => $context['user']->id]);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $exception->id, 'action' => 'migration_exception_resolved']);

        $restricted = $this->stage3AccountingContext(['migration.view']);
        $foreignException = MigrationException::factory()->for(LegacyImportRun::factory()->for($restricted['company']), 'importRun')->for($restricted['company'])->create();
        $this->patchJson("/api/v1/pakistan-fbr/migration-exceptions/{$foreignException->id}", ['resolution_state' => 'IGNORED', 'resolution_note' => 'Attempted without permission.'], ['X-Company-Id' => $restricted['company']->id])->assertForbidden();
    }
}
