<?php

namespace Tests\Feature\Stage15;

use App\Contracts\FbrGateway;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\LegacyEntityMap;
use App\Models\LegacyFbrEvidence;
use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrSubmissionAttempt;
use App\Models\User;
use App\Services\Migration\LegacyInvoiceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsLegacyInvoiceSnapshots;
use Tests\TestCase;

class TechnicalIdentifierTest extends TestCase
{
    use BuildsLegacyInvoiceSnapshots, RefreshDatabase;

    public function test_request_normalization_preserves_identifiers_but_still_trims_human_fields(): void
    {
        $this->app['router']->post('/api/identifier-normalization-test', fn (Request $request): array => $request->all());
        $this->postJson('/api/identifier-normalization-test', [
            'idempotency_key' => 'Retry-A ', 'invoice_number' => 'Invoice-A ', 'name' => ' Human Name ',
            'nested' => ['source_id' => '001 ', 'description' => ' Human description '],
        ])->assertOk()->assertJsonPath('idempotency_key', 'Retry-A ')->assertJsonPath('invoice_number', 'Invoice-A ')
            ->assertJsonPath('nested.source_id', '001 ')->assertJsonPath('name', 'Human Name')->assertJsonPath('nested.description', 'Human description');
    }

    /** @return array<string, array{string, string}> */
    public static function exactPairs(): array
    {
        return ['case' => ['Retry-A', 'retry-a'], 'space' => ['Retry-A', 'Retry-A '], 'accent' => ['Resume', 'Resumé'], 'normalization' => ['é', "e\u{0301}"]];
    }

    #[DataProvider('exactPairs')]
    public function test_invoice_numbers_and_creation_keys_remain_distinct(string $first, string $second): void
    {
        $company = Company::factory()->create();
        foreach ([Invoice::class, PakistanFbrInvoice::class] as $model) {
            $one = $model::factory()->for($company)->create(['invoice_number' => $first, 'creation_idempotency_key' => $first]);
            $two = $model::factory()->for($company)->create(['invoice_number' => $second, 'creation_idempotency_key' => $second]);
            $this->assertNotSame($one->id, $two->id);
            $this->assertSame($first, $one->fresh()->invoice_number);
            $this->assertSame($second, $two->fresh()->invoice_number);
            foreach (['invoice_number', 'creation_idempotency_key'] as $column) {
                $this->assertSame($one->id, $model::query()->where('company_id', $company->id)->where($column, $first)->sole()->id);
                $this->assertSame($two->id, $model::query()->where('company_id', $company->id)->where($column, $second)->sole()->id);
            }
        }
    }

    #[DataProvider('exactPairs')]
    public function test_source_and_submission_keys_and_reference_queries_are_exact(string $first, string $second): void
    {
        $invoice = PakistanFbrInvoice::factory()->create();
        foreach ([$first, $second] as $value) {
            PakistanFbrSubmissionAttempt::factory()->for($invoice, 'invoice')->create([
                'company_id' => $invoice->company_id, 'idempotency_key' => $value, 'reference_number' => $value,
            ]);
            LegacyEntityMap::factory()->create(['company_id' => $invoice->company_id, 'source_system' => $value, 'source_id' => $value]);
        }
        foreach ([$first, $second] as $value) {
            $this->assertSame($value, PakistanFbrSubmissionAttempt::query()->where('invoice_id', $invoice->id)->where('idempotency_key', $value)->sole()->reference_number);
            $this->assertSame($value, PakistanFbrSubmissionAttempt::query()->where('reference_number', $value)->sole()->idempotency_key);
            $this->assertSame($value, LegacyEntityMap::query()->where('source_system', $value)->sole()->source_id);
            $this->assertSame($value, LegacyEntityMap::query()->where('source_id', $value)->sole()->source_system);
        }
    }

    public function test_historical_numbers_and_references_preserve_exact_values_and_duplicate_evidence(): void
    {
        Http::preventStrayRequests();
        $this->mock(FbrGateway::class)->shouldNotReceive('submit');
        $snapshot = $this->legacySnapshot(2);
        $snapshot['invoices'][0]['invoice_number'] = 'Legacy-A ';
        $snapshot['invoices'][1]['invoice_number'] = 'Legacy-A';
        foreach ($snapshot['fbr_invoice_submissions'] as &$submission) {
            $submission['fbr_invoice_number'] = 'Provider-A ';
        }
        unset($submission);
        $report = app(LegacyInvoiceImportService::class)->execute($snapshot, Company::factory()->create(), User::factory()->create(), '4', hash('sha256', 'exact-history'), 'synthetic.json');

        $this->assertSame(0, $report['unexplained_discrepancy_count']);
        $this->assertSame('Legacy-A ', PakistanFbrInvoice::query()->where('legacy_source_id', '1')->sole()->invoice_number);
        $this->assertSame(2, LegacyFbrEvidence::query()->where('fbr_reference_number', 'Provider-A ')->count());
        $this->assertSame(0, LegacyFbrEvidence::query()->where('fbr_reference_number', 'Provider-A')->count());
        $this->assertDatabaseHas('migration_exceptions', ['exception_code' => 'DUPLICATE_FBR_REFERENCE']);
        Http::assertNothingSent();
    }

    public function test_identifier_schema_has_exact_collations_on_mariadb(): void
    {
        $migration = require database_path('migrations/2026_10_02_094522_enforce_exact_technical_identifier_collations.php');
        $count = 0;
        foreach ($migration::IDENTIFIERS as $table => $columns) {
            $actual = collect(Schema::getColumns($table))->keyBy('name');
            foreach ($columns as $column => [$length, $nullable]) {
                $this->assertArrayHasKey($column, $actual->all());
                if (DB::getDriverName() !== 'sqlite') {
                    $this->assertSame('utf8mb4_nopad_bin', $actual[$column]['collation']);
                    $this->assertSame($nullable, $actual[$column]['nullable']);
                }
                $count++;
            }
        }
        $this->assertSame(52, $count);
        if (DB::getDriverName() !== 'sqlite') {
            foreach (self::exactPairs() as [$left, $right]) {
                $result = DB::selectOne('SELECT CONVERT(? USING utf8mb4) COLLATE utf8mb4_nopad_bin = CONVERT(? USING utf8mb4) COLLATE utf8mb4_nopad_bin AS identical', [$left, $right]);
                $this->assertSame(0, (int) $result->identical);
            }
        }
    }
}
