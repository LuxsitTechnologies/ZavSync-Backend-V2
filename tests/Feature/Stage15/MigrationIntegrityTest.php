<?php

namespace Tests\Feature\Stage15;

use App\Models\LegacyImportRun;
use App\Models\PakistanFbrInvoice;
use App\Services\Migration\ExactDecimalConverter;
use App\Services\Migration\LegacyInvoiceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsLegacyInvoiceSnapshots;
use Tests\TestCase;

class MigrationIntegrityTest extends TestCase
{
    use BuildsLegacyInvoiceSnapshots, RefreshDatabase;

    public function test_source_company_mapping_cannot_be_reused_in_another_tenant(): void
    {
        $owner = $this->stage3AccountingContext();
        $other = $this->stage3AccountingContext();
        $importer = app(LegacyInvoiceImportService::class);
        $importer->execute($this->legacySnapshot(), $owner['company'], $owner['user'], '4', hash('sha256', 'owner'), 'owner.json');
        try {
            $importer->execute($this->legacySnapshot(), $other['company'], $other['user'], '4', hash('sha256', 'other'), 'other.json');
            $this->fail('A source company was remapped across tenants.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company', $exception->errors());
        }
        $this->assertSame(0, PakistanFbrInvoice::query()->where('company_id', $other['company']->id)->count());
        $this->assertDatabaseHas('migration_exceptions', ['company_id' => $other['company']->id, 'exception_code' => 'CROSS_TENANT_REFERENCE']);
    }

    public function test_changed_export_records_conflict_without_overwriting_imported_evidence(): void
    {
        $context = $this->stage3AccountingContext();
        $importer = app(LegacyInvoiceImportService::class);
        $snapshot = $this->legacySnapshot();
        $importer->execute($snapshot, $context['company'], $context['user'], '4', hash('sha256', 'before'), 'before.json');
        $snapshot['invoices'][0]['total_amount'] = '200.00';
        $report = $importer->execute($snapshot, $context['company'], $context['user'], '4', hash('sha256', 'after'), 'after.json');
        $this->assertDatabaseCount('pakistan_fbr_invoices', 1);
        $this->assertSame(11800, PakistanFbrInvoice::query()->sole()->total);
        $this->assertGreaterThan(0, $report['unexplained_discrepancy_count']);
        $this->assertDatabaseHas('migration_exceptions', ['exception_code' => 'SOURCE_RECORD_CHANGED']);
    }

    public function test_same_records_in_a_new_export_reconcile_to_existing_crosswalk_without_duplicate_records(): void
    {
        $context = $this->stage3AccountingContext();
        $importer = app(LegacyInvoiceImportService::class);
        $importer->execute($this->legacySnapshot(), $context['company'], $context['user'], '4', hash('sha256', 'export-one'), 'one.json');
        $report = $importer->execute($this->legacySnapshot(), $context['company'], $context['user'], '4', hash('sha256', 'export-two'), 'two.json');
        $this->assertSame(0, $report['unexplained_discrepancy_count']);
        $this->assertTrue($report['crosswalk_complete']);
        $this->assertSame(1, $report['target_counts']['invoices']);
        $this->assertDatabaseCount('pakistan_fbr_invoices', 1);
    }

    public function test_dry_run_reports_exact_totals_mappings_and_known_financial_fbr_exceptions_without_writes(): void
    {
        $context = $this->stage3AccountingContext();
        $report = app(LegacyInvoiceImportService::class)->execute($this->legacySnapshot(128), $context['company'], $context['user'], '4', hash('sha256', 'dry-complete'), 'dry.json', true);
        $codes = collect($report['exceptions'])->countBy('exception_code');
        $this->assertSame(2, $codes['FINANCIAL_SUBTOTAL_MISMATCH']);
        $this->assertSame(1, $codes['FINANCIAL_TAX_MISMATCH']);
        $this->assertSame(2, $codes['FINANCIAL_TOTAL_MISMATCH']);
        $this->assertSame(3, $codes['AMBIGUOUS_FBR_SUCCESS']);
        $this->assertSame(1280200, $report['financial_totals_minor']['subtotal']);
        $this->assertCount(128, $report['customer_mappings']);
        $this->assertSame($context['company']->id, $report['company_mapping']['target_id']);
        foreach (['legacy_import_runs', 'migration_exceptions', 'pakistan_fbr_invoices', 'audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_partial_import_failure_rolls_back_its_chunk_and_resumes_without_duplication(): void
    {
        $context = $this->stage3AccountingContext();
        config(['legacy_migration.chunk_size' => 1]);
        $fail = true;
        PakistanFbrInvoice::creating(function (PakistanFbrInvoice $invoice) use (&$fail): void {
            if ($invoice->legacy_source_id === '2' && $fail) {
                $fail = false;
                throw new \RuntimeException('Simulated database interruption.');
            }
        });
        $importer = app(LegacyInvoiceImportService::class);
        $fingerprint = hash('sha256', 'interrupted-source');
        try {
            $importer->execute($this->legacySnapshot(2), $context['company'], $context['user'], '4', $fingerprint, 'interrupted.json');
            $this->fail('The simulated interruption did not occur.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated database interruption.', $exception->getMessage());
        }
        $run = LegacyImportRun::query()->sole();
        $this->assertSame('FAILED', $run->status);
        $this->assertDatabaseCount('pakistan_fbr_invoices', 1);
        $report = $importer->execute($this->legacySnapshot(2), $context['company'], $context['user'], '4', $fingerprint, 'interrupted.json', false, $run->id);
        $this->assertSame(0, $report['unexplained_discrepancy_count']);
        $this->assertDatabaseCount('pakistan_fbr_invoices', 2);
        $this->assertDatabaseCount('legacy_fbr_evidence', 2);
        $this->assertSame(2, $run->fresh()->progress['invoices_processed']);
    }

    public function test_untrusted_sql_text_is_stored_as_data_and_unknown_manifest_fields_are_not_persisted(): void
    {
        $context = $this->stage3AccountingContext();
        $snapshot = $this->legacySnapshot();
        $snapshot['invoices'][0]['buyer_name'] = "Buyer'); DROP TABLE users; --";
        $snapshot['manifest']['credential'] = 'must-not-be-persisted';
        $snapshot['manifest']['financial_totals']['subtotal'] = 'token=must-not-be-persisted';
        $report = app(LegacyInvoiceImportService::class)->execute($snapshot, $context['company'], $context['user'], '4', hash('sha256', 'untrusted'), 'untrusted.json');
        $this->assertSame($snapshot['invoices'][0]['buyer_name'], PakistanFbrInvoice::query()->sole()->buyer_snapshot['name']);
        $this->assertDatabaseHas('users', ['id' => $context['user']->id]);
        $this->assertStringNotContainsString('must-not-be-persisted', json_encode($report, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('must-not-be-persisted', LegacyImportRun::query()->sole()->toJson());
    }

    public function test_orphan_submission_is_rejected_without_silently_losing_it(): void
    {
        $context = $this->stage3AccountingContext();
        $snapshot = $this->legacySnapshot();
        $snapshot['fbr_invoice_submissions'][0]['invoice_id'] = 999;
        try {
            app(LegacyInvoiceImportService::class)->execute($snapshot, $context['company'], $context['user'], '4', hash('sha256', 'orphan'), 'orphan.json');
            $this->fail('An orphan source reference was ignored.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('SOURCE_ORPHAN_REFERENCE', $exception->errors());
        }
        $this->assertDatabaseCount('legacy_import_runs', 0);
    }

    public function test_integer_aggregation_rejects_overflow_instead_of_promoting_to_float(): void
    {
        $converter = new ExactDecimalConverter;
        $this->assertSame(PHP_INT_MAX, $converter->sum([PHP_INT_MAX - 1, 1]));
        $this->expectException(\InvalidArgumentException::class);
        $converter->sum([PHP_INT_MAX, 1]);
    }

    public function test_reference_and_tax_identifier_issues_are_recorded_without_rewriting_original_values(): void
    {
        $context = $this->stage3AccountingContext();
        $snapshot = $this->legacySnapshot();
        $snapshot['invoices'][0]['buyer_registration_no'] = '12345';
        app(LegacyInvoiceImportService::class)->execute($snapshot, $context['company'], $context['user'], '4', hash('sha256', 'references'), 'references.json');
        $this->assertDatabaseHas('migration_exceptions', ['exception_code' => 'INVALID_TAX_IDENTIFIER']);
        $this->assertDatabaseHas('migration_exceptions', ['exception_code' => 'UNKNOWN_REFERENCE_DATA']);
        $this->assertSame('12345', PakistanFbrInvoice::query()->sole()->buyer_snapshot['registration_number']);
    }
}
