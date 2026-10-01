<?php

namespace Tests\Feature\Stage15;

use App\Models\AuditLog;
use App\Models\MigrationException;
use App\Services\Migration\LegacyImportFileReader;
use App\Services\Migration\LegacyInvoiceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsLegacyInvoiceSnapshots;
use Tests\TestCase;

class MigrationToolingTest extends TestCase
{
    use BuildsLegacyInvoiceSnapshots, RefreshDatabase;

    public function test_reconciliation_command_is_read_only_and_reports_import_exceptions(): void
    {
        Storage::fake('legacy-reconcile-test');
        $disk = Storage::disk('legacy-reconcile-test');
        $disk->makeDirectory('inputs');
        $disk->put('inputs/snapshot.json', json_encode($this->legacySnapshot(), JSON_THROW_ON_ERROR));
        config(['legacy_migration.input_root' => $disk->path('inputs')]);
        $context = $this->stage3AccountingContext(['migration.manage']);
        $options = ['path' => $disk->path('inputs/snapshot.json'), '--company' => $context['company']->id, '--source-company' => '4', '--actor' => (string) $context['user']->id];
        $this->assertSame(0, Artisan::call('legacy:invoice-import', $options));
        $auditCount = AuditLog::query()->count();
        $exceptionCount = MigrationException::query()->count();

        $this->assertSame(0, Artisan::call('legacy:invoice-import', [...$options, '--reconcile-only' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($report['writes_performed']);
        $this->assertSame(0, $report['unexplained_discrepancy_count']);
        $this->assertCount($exceptionCount, $report['exceptions']);
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertSame($exceptionCount, MigrationException::query()->count());
    }

    public function test_command_rejects_actor_without_migration_permission_before_reading_input(): void
    {
        $context = $this->stage3AccountingContext([]);
        $this->assertSame(2, Artisan::call('legacy:invoice-import', ['path' => '/not-readable.json', '--company' => $context['company']->id, '--source-company' => '4', '--actor' => (string) $context['user']->id]));
        $this->assertStringContainsString('not authorized', Artisan::output());
        $this->assertDatabaseCount('legacy_import_runs', 0);
    }

    public function test_dry_run_reports_conversion_and_reconciliation_without_database_writes(): void
    {
        $context = $this->stage3AccountingContext();

        $report = app(LegacyInvoiceImportService::class)->execute($this->legacySnapshot(), $context['company'], $context['user'], '4', hash('sha256', 'dry-run'), 'dry-run.json', true);

        $this->assertSame('DRY_RUN', $report['mode']);
        $this->assertFalse($report['writes_performed']);
        $this->assertSame(['invoices' => 1, 'invoice_lines' => 1, 'fbr_submissions' => 1], $report['source_counts']);
        $this->assertDatabaseCount('legacy_import_runs', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_command_dry_run_reads_only_json_from_configured_private_root(): void
    {
        Storage::fake('legacy-import-test');
        $root = Storage::disk('legacy-import-test')->path('inputs');
        Storage::disk('legacy-import-test')->makeDirectory('inputs');
        Storage::disk('legacy-import-test')->put('inputs/snapshot.json', json_encode($this->legacySnapshot(), JSON_THROW_ON_ERROR));
        config(['legacy_migration.input_root' => $root]);
        $context = $this->stage3AccountingContext(['migration.manage']);

        $exitCode = Artisan::call('legacy:invoice-import', ['path' => $root.'/snapshot.json', '--company' => $context['company']->id, '--source-company' => '4', '--actor' => (string) $context['user']->id, '--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('"writes_performed": false', $output);

        $this->assertDatabaseCount('legacy_import_runs', 0);
    }

    public function test_reader_rejects_path_traversal_invalid_json_and_oversized_input(): void
    {
        Storage::fake('legacy-security-test');
        $root = Storage::disk('legacy-security-test')->path('inputs');
        Storage::disk('legacy-security-test')->makeDirectory('inputs');
        config(['legacy_migration.input_root' => $root, 'legacy_migration.maximum_input_bytes' => 20]);
        $reader = app(LegacyImportFileReader::class);

        try {
            $reader->read('/etc/passwd');
            $this->fail('A path outside the private import root was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('path', $exception->errors());
        }

        Storage::disk('legacy-security-test')->put('inputs/malicious.json', '{"source_system":');
        try {
            $reader->read($root.'/malicious.json');
            $this->fail('Malformed JSON was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('path', $exception->errors());
        }

        Storage::disk('legacy-security-test')->put('inputs/oversized.json', str_repeat('x', 21));
        $this->expectException(ValidationException::class);
        $reader->read($root.'/oversized.json');
    }
}
