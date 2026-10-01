<?php

namespace Tests\Feature\Stage15;

use App\Models\FbrReferenceValue;
use App\Models\LegacyEntityMap;
use App\Services\Migration\LegacyInvoiceImportService;
use Closure;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsLegacyInvoiceSnapshots;
use Tests\TestCase;

class Stage15ReadinessTest extends TestCase
{
    use BuildsLegacyInvoiceSnapshots, RefreshDatabase;

    public function test_target_mapping_uniqueness_is_enforced_even_when_application_prechecks_are_bypassed(): void
    {
        $context = $this->stage3AccountingContext();
        app(LegacyInvoiceImportService::class)->execute($this->legacySnapshot(), $context['company'], $context['user'], '4', hash('sha256', 'constraint'), 'fixture.json');
        $mapping = LegacyEntityMap::query()->where('source_entity_type', 'company')->sole();
        $this->expectException(UniqueConstraintViolationException::class);
        LegacyEntityMap::factory()->create([
            'import_run_id' => $mapping->import_run_id, 'company_id' => $context['company']->id,
            'source_system' => $mapping->source_system, 'source_entity_type' => 'company',
            'source_id' => '6', 'target_entity_type' => $mapping->target_entity_type, 'target_id' => $mapping->target_id,
        ]);
    }

    public function test_a_second_source_company_cannot_be_mapped_to_an_existing_target(): void
    {
        $context = $this->stage3AccountingContext();
        $importer = app(LegacyInvoiceImportService::class);
        $snapshot = $this->legacySnapshot();
        $importer->execute($snapshot, $context['company'], $context['user'], '4', hash('sha256', 'first'), 'first.json');
        $snapshot['invoices'][0]['company_id'] = '6';
        try {
            $importer->execute($snapshot, $context['company'], $context['user'], '6', hash('sha256', 'second'), 'second.json');
            $this->fail('A target accepted conflicting source companies.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company', $exception->errors());
        }
        $this->assertSame(1, LegacyEntityMap::query()->where('source_entity_type', 'company')->count());
        $this->assertDatabaseCount('pakistan_fbr_invoices', 1);
        $this->assertDatabaseHas('migration_exceptions', ['exception_code' => 'CROSS_TENANT_REFERENCE']);
    }

    public function test_reference_lookup_supports_parent_version_and_inclusive_effective_dates(): void
    {
        [, $company] = $this->actingAsCompanyUser(['fbr.configuration.view']);
        $attributes = ['category' => 'SRO_ITEM', 'parent_code' => 'SRO-A', 'source_version' => 'fixture-v1', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31'];
        FbrReferenceValue::factory()->create([...$attributes, 'code' => 'MATCH']);
        FbrReferenceValue::factory()->create([...$attributes, 'code' => 'OTHER-PARENT', 'parent_code' => 'SRO-B']);
        FbrReferenceValue::factory()->create([...$attributes, 'code' => 'EXPIRED', 'valid_until' => '2025-12-31']);
        FbrReferenceValue::factory()->create([...$attributes, 'code' => 'OTHER-VERSION', 'source_version' => 'fixture-v2']);
        foreach (['2026-01-01', '2026-12-31'] as $effectiveDate) {
            $this->getJson('/api/v1/pakistan-fbr/reference-data?category=SRO_ITEM&parent_code=SRO-A&source_version=fixture-v1&effective_on='.$effectiveDate, ['X-Company-Id' => $company->id])
                ->assertOk()->assertJsonCount(1)->assertJsonPath('0.code', 'MATCH');
        }
        $this->getJson('/api/v1/pakistan-fbr/reference-data?effective_on=invalid', ['X-Company-Id' => $company->id])->assertUnprocessable();
    }

    public function test_all_foundation_mysql_grammar_identifiers_fit_mariadb_limits_without_connecting_to_a_server(): void
    {
        $connection = new class(null, 'static_compilation_only', '', ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']) extends MySqlConnection
        {
            public function isMaria(): bool
            {
                return true;
            }

            public function getServerVersion(): string
            {
                return '11.8.9';
            }
        };
        $connection->useDefaultSchemaGrammar();
        $statements = [];
        Schema::shouldReceive('create')->times(9)->andReturnUsing(function (string $table, Closure $callback) use ($connection, &$statements): void {
            $blueprint = new Blueprint($connection, $table);
            $blueprint->create();
            $callback($blueprint);
            array_push($statements, ...$blueprint->toSql());
        });
        $migration = require database_path('migrations/2026_10_01_145329_create_stage15a_legacy_invoice_fbr_foundation.php');
        $migration->up();
        Schema::shouldReceive('table')->once()->andReturnUsing(function (string $table, Closure $callback) use ($connection, &$statements): void {
            $blueprint = new Blueprint($connection, $table, $callback);
            array_push($statements, ...$blueprint->toSql());
        });
        $correction = require database_path('migrations/2026_10_01_201506_add_target_uniqueness_to_legacy_entity_maps.php');
        $correction->up();
        $identifiers = [];
        foreach ($statements as $statement) {
            preg_match_all('/(?:constraint|index|unique) `([^`]+)`/i', $statement, $matches);
            array_push($identifiers, ...$matches[1]);
        }
        $this->assertNotEmpty($identifiers);
        foreach ($identifiers as $identifier) {
            $this->assertLessThanOrEqual(64, strlen($identifier), $identifier);
        }
        $this->assertContains('legacy_import_source_fingerprint_unique', $identifiers);
        $this->assertContains('legacy_fbr_status_review_index', $identifiers);
        $this->assertContains('legacy_entity_maps_target_unique', $identifiers);
    }
}
