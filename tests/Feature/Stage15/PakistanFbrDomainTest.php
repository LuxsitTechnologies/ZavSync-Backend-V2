<?php

namespace Tests\Feature\Stage15;

use App\Contracts\FbrGateway;
use App\Enums\FbrSubmissionStatus;
use App\Exceptions\FbrUnavailableException;
use App\Models\FbrCompanyConfiguration;
use App\Models\Invoice;
use App\Models\MigrationException;
use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrInvoiceLine;
use App\Models\PakistanFbrSubmissionAttempt;
use App\Services\Accounting\InvoiceCalculationService;
use App\Services\Fbr\FbrSubmissionContext;
use App\Services\Fbr\FbrSubmissionResult;
use App\Services\Fbr\PakistanFbrPayloadMapper;
use App\Services\Migration\LegacyInvoiceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyInvoiceSnapshots;
use Tests\TestCase;

class PakistanFbrDomainTest extends TestCase
{
    use BuildsLegacyInvoiceSnapshots, RefreshDatabase;

    public function test_new_draft_is_authoritatively_calculated_idempotent_and_completely_separate_from_accounting(): void
    {
        $context = $this->context();
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'pk-create'];
        $payload = [...$this->payload(), 'total' => 1, 'is_historical' => true, 'journal_id' => fake()->uuid(), 'invoice_number' => 'USER-NUMBER', 'company_id' => fake()->uuid()];

        $id = $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, $headers)->assertCreated()
            ->assertJsonPath('total', 11800)->assertJsonPath('invoice_number', 'PKF-00000001')
            ->assertJsonPath('domain', 'pakistan_fbr')->assertJsonPath('is_historical', false)
            ->assertJsonPath('module_name', 'FBR Invoicing')->assertJsonPath('capabilities.print_data', true)
            ->assertJsonPath('capabilities.regulatory_print_status', 'STAGING_CERTIFICATION_REQUIRED')
            ->assertJsonPath('capabilities.qr_content', null)->assertJsonPath('capabilities.buyer_registration_check', false)
            ->assertJsonPath('accounting_integration', 'NOT_INTEGRATED')->json('id');
        $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, $headers)->assertOk()->assertJsonPath('id', $id);
        $changed = $this->payload();
        $changed['lines'][0]['unit_price'] = 20000;
        $this->postJson('/api/v1/pakistan-fbr/invoices', $changed, $headers)->assertConflict();
        $this->patchJson("/api/v1/pakistan-fbr/invoices/{$id}", $changed, $headers)->assertOk()->assertJsonPath('total', 23600);
        $this->getJson("/api/v1/accounting/invoices/{$id}", $headers)->assertNotFound();
        $this->postJson("/api/v1/accounting/invoices/{$id}/post", [], $headers)->assertNotFound();
        $this->assertDatabaseCount('pakistan_fbr_invoices', 1);
        $this->assertDatabaseCount('pakistan_fbr_invoice_lines', 1);
        $this->assertNoAccountingEffects();
    }

    public function test_explicit_tax_amounts_are_exact_and_withholding_does_not_reduce_new_fbr_total(): void
    {
        $context = $this->context();
        $payload = $this->payload();
        $payload['lines'][0]['unit_price'] = 100000;
        $payload['lines'][0]['sales_tax'] = 18001;
        $payload['lines'][0]['extra_tax'] = 1;
        $payload['lines'][0]['further_tax'] = 200;
        $payload['lines'][0]['st_withheld'] = 500;

        $response = $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, [
            'X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'explicit-tax',
        ])->assertCreated()->assertJsonPath('subtotal', 100000)->assertJsonPath('sales_tax', 18001)
            ->assertJsonPath('extra_tax', 1)->assertJsonPath('further_tax', 200)
            ->assertJsonPath('withholding_tax', 500)->assertJsonPath('total', 118202)
            ->assertJsonPath('lines.0.total', 118202)->assertJsonPath('lines.0.tax_amount', 18001)
            ->assertJsonPath('lines.0.sales_tax', 18001)
            ->assertJsonPath('lines.0.tax_rate_bps', 1800)->assertJsonPath('lines.0.fbr_rate_id', '18%')
            ->assertJsonPath('lines.0.other_tax_amount', 1)
            ->assertJsonPath('lines.0.advance_tax_amount', 200)->assertJsonPath('lines.0.withholding_tax_amount', 500)
            ->assertJsonPath('lines.0.extra_tax', 1)->assertJsonPath('lines.0.further_tax', 200)
            ->assertJsonPath('lines.0.st_withheld', 500);

        $this->assertDatabaseHas('pakistan_fbr_invoices', ['id' => $response->json('id'), 'total' => 118202, 'withholding_tax' => 500]);
        $configuration = FbrCompanyConfiguration::factory()->for($context['company'])->create(['updated_by' => $context['user']->id]);
        $mapped = app(PakistanFbrPayloadMapper::class)->map(PakistanFbrInvoice::query()->with('lines')->findOrFail($response->json('id')), $configuration);
        $this->assertSame('180.01', $mapped['items'][0]['salesTaxApplicable']);
        $this->assertSame('18%', $mapped['items'][0]['rate']);
        $this->assertSame('0.01', $mapped['items'][0]['extraTax']);
        $this->assertSame('2.00', $mapped['items'][0]['furtherTax']);
        $this->assertSame('5.00', $mapped['items'][0]['salesTaxWithheldAtSource']);
        $this->assertNoAccountingEffects();
    }

    public function test_positive_one_paisa_sales_tax_overrides_rate_and_zero_or_absent_uses_rate(): void
    {
        $context = $this->context();
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'one-paisa-sales-tax'];
        $payload = $this->payload();
        $payload['lines'][0]['sales_tax'] = 1;

        $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, $headers)
            ->assertCreated()->assertJsonPath('sales_tax', 1)->assertJsonPath('total', 10001)
            ->assertJsonPath('lines.0.tax_rate_bps', 1800);

        $payload['lines'][0]['unit_price'] = 9007199254740000;
        $headers['Idempotency-Key'] = 'large-safe-sales-tax';
        $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, $headers)
            ->assertCreated()->assertJsonPath('sales_tax', 1)->assertJsonPath('total', 9007199254740001)
            ->assertJsonPath('lines.0.tax_rate_bps', 1800);
        $payload['lines'][0]['unit_price'] = 10000;

        $payload['lines'][0]['sales_tax'] = 0;
        $headers['Idempotency-Key'] = 'zero-sales-tax';
        $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, $headers)
            ->assertCreated()->assertJsonPath('sales_tax', 1800)->assertJsonPath('total', 11800);

        $payload['lines'][0]['sales_tax'] = null;
        $headers['Idempotency-Key'] = 'null-sales-tax';
        $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, $headers)
            ->assertCreated()->assertJsonPath('sales_tax', 1800)->assertJsonPath('total', 11800);

        unset($payload['lines'][0]['sales_tax']);
        $headers['Idempotency-Key'] = 'absent-sales-tax';
        $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, $headers)
            ->assertCreated()->assertJsonPath('sales_tax', 1800)->assertJsonPath('total', 11800);

        $payload['lines'][0]['sales_tax'] = 9007199254740991;
        $headers['Idempotency-Key'] = 'overflow-sales-tax';
        $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, $headers)->assertUnprocessable();
        $this->assertDatabaseCount('pakistan_fbr_invoices', 5);
        $this->assertNoAccountingEffects();
    }

    public function test_native_accounting_calculator_retains_withholding_payable_semantics(): void
    {
        $calculated = app(InvoiceCalculationService::class)->calculate([[
            'description' => 'Native accounting line', 'quantity_milli' => 1000, 'unit' => 'unit',
            'unit_price' => 10000, 'tax_rate_bps' => 1800, 'other_tax_rate_bps' => 0,
            'advance_tax_rate_bps' => 0, 'withholding_tax_rate_bps' => 500, 'sales_type' => 'standard',
        ]]);

        $this->assertSame(11300, $calculated['totals']['total']);
        $this->assertSame(500, $calculated['totals']['withholding_tax']);
    }

    public function test_explicit_zero_tax_and_large_totals_are_exact_while_overflow_and_ambiguous_rates_are_rejected(): void
    {
        $context = $this->context();
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'zero-tax'];
        $payload = $this->payload();
        $payload['lines'][0]['extra_tax'] = 0;
        $payload['lines'][0]['further_tax'] = 0;
        $payload['lines'][0]['st_withheld'] = 0;
        $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, $headers)->assertCreated()->assertJsonPath('total', 11800);

        $payload['lines'][0]['extra_tax'] = 9007199254730000;
        $payload['lines'][0]['tax_rate_bps'] = 0;
        $headers['Idempotency-Key'] = 'large-tax';
        $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, $headers)->assertCreated()->assertJsonPath('total', 9007199254740000);

        $payload['lines'][0]['extra_tax'] = 9007199254740991;
        $headers['Idempotency-Key'] = 'overflow-tax';
        $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, $headers)->assertUnprocessable();

        $payload['lines'][0]['extra_tax'] = 1;
        $payload['lines'][0]['other_tax_rate_bps'] = 100;
        $headers['Idempotency-Key'] = 'ambiguous-tax';
        $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, $headers)->assertUnprocessable()->assertJsonValidationErrors(['lines.0.extra_tax']);
        $this->assertDatabaseCount('pakistan_fbr_invoices', 2);
        $this->assertNoAccountingEffects();
    }

    public function test_existing_rate_inputs_remain_supported_without_subtracting_withholding(): void
    {
        $context = $this->context();
        $payload = $this->payload();
        $payload['lines'][0]['other_tax_rate_bps'] = 100;
        $payload['lines'][0]['advance_tax_rate_bps'] = 200;
        $payload['lines'][0]['withholding_tax_rate_bps'] = 500;

        $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, [
            'X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'rate-compatibility',
        ])->assertCreated()->assertJsonPath('extra_tax', 100)->assertJsonPath('further_tax', 200)
            ->assertJsonPath('withholding_tax', 500)->assertJsonPath('total', 12100);
        $this->assertNoAccountingEffects();
    }

    public function test_scenario_is_derived_from_buyer_type_and_cannot_be_selected_by_client(): void
    {
        $context = $this->context();
        $configuration = FbrCompanyConfiguration::factory()->for($context['company'])->create(['updated_by' => $context['user']->id]);
        foreach (['Registered' => 'SN001', 'Unregistered' => 'SN002'] as $type => $scenario) {
            $payload = $this->payload();
            $payload['buyer_snapshot']['type'] = $type;
            $payload['scenario_id'] = 'UNTRUSTED';
            $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'scenario-'.$type];
            $response = $this->postJson('/api/v1/pakistan-fbr/invoices', $payload, $headers)->assertCreated()->assertJsonPath('scenario_id', $scenario);
            $invoice = PakistanFbrInvoice::query()->findOrFail($response->json('id'));
            $this->assertSame($scenario, app(PakistanFbrPayloadMapper::class)->map($invoice->load('lines'), $configuration)['scenarioId']);
        }
        $invalid = $this->payload();
        $invalid['buyer_snapshot']['type'] = 'Unknown';
        $this->postJson('/api/v1/pakistan-fbr/invoices', $invalid, ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'invalid-scenario'])
            ->assertUnprocessable()->assertJsonValidationErrors(['buyer_snapshot.type']);
        $this->assertNoAccountingEffects();
    }

    public function test_new_submission_and_repeat_are_idempotent_immutable_and_have_no_accounting_effects(): void
    {
        $context = $this->context();
        $invoice = $this->draft($context);
        $gateway = $this->gateway();
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'pk-submit'];

        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/submit", [], $headers)
            ->assertOk()->assertJsonPath('fbr_reference_number', 'PK-ACCEPTED')->assertJsonPath('document_state', 'ISSUED')->assertJsonPath('editable', false);
        $headers['Idempotency-Key'] = 'different-key';
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/submit", [], $headers)->assertOk();
        $this->patchJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}", $this->payload(), $headers)->assertUnprocessable();
        $this->getJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/attempts", $headers)->assertOk()->assertJsonCount(1)->assertJsonPath('0.status', 'accepted');
        $this->assertSame(1, $gateway->calls);
        $this->assertStringStartsWith('pkfbr:'.$invoice->id.':', $gateway->keys[0]);
        $this->assertDatabaseCount('pakistan_fbr_submission_attempts', 1);
        $this->assertNoAccountingEffects();
    }

    public function test_unavailable_provider_requires_original_key_and_payload_for_safe_retry(): void
    {
        $context = $this->context();
        $invoice = $this->draft($context);
        $gateway = $this->gateway(true);
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'safe-retry'];
        $url = "/api/v1/pakistan-fbr/invoices/{$invoice->id}/submit";

        $this->postJson($url, [], $headers)->assertServiceUnavailable()->assertJsonMissing(['message' => 'token=never-store-this']);
        $this->patchJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}", $this->payload(), $headers)->assertUnprocessable();
        $this->postJson($url, [], [...$headers, 'Idempotency-Key' => 'unsafe-new-key'])->assertConflict();
        $this->postJson($url, [], $headers)->assertOk()->assertJsonPath('fbr_status', 'accepted');
        $this->assertSame(2, $gateway->calls);
        $this->assertSame($gateway->keys[0], $gateway->keys[1]);
        $this->assertSame(1, PakistanFbrSubmissionAttempt::query()->count());
        $this->assertStringNotContainsString('never-store-this', PakistanFbrSubmissionAttempt::query()->sole()->toJson());
        $this->assertNoAccountingEffects();
    }

    public function test_server_retry_recovers_original_provider_identity_without_browser_key(): void
    {
        $context = $this->context();
        $invoice = $this->draft($context);
        $gateway = $this->gateway(true);
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'original-browser-key'];
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/submit", [], $headers)->assertServiceUnavailable();

        Sanctum::actingAs($context['user']);
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/retry", [], ['X-Company-Id' => $context['company']->id])
            ->assertOk()->assertJsonPath('fbr_status', 'accepted')->assertJsonPath('fbr_reference_number', 'PK-ACCEPTED')
            ->assertJsonPath('capabilities.retry_recovery', false);
        $this->assertSame(2, $gateway->calls);
        $this->assertSame($gateway->keys[0], $gateway->keys[1]);
        $this->assertDatabaseCount('pakistan_fbr_submission_attempts', 1);
        $this->assertSame(2, PakistanFbrSubmissionAttempt::query()->sole()->claim_generation);
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/retry", [], ['X-Company-Id' => $context['company']->id])->assertUnprocessable();
        $this->assertNoAccountingEffects();
    }

    public function test_server_retry_respects_active_lease_and_reclaims_only_after_expiry(): void
    {
        $context = $this->context();
        $invoice = $this->draft($context);
        $gateway = $this->gateway();
        $configuration = FbrCompanyConfiguration::query()->where('company_id', $context['company']->id)->sole();
        $payload = app(PakistanFbrPayloadMapper::class)->map($invoice->load('lines'), $configuration);
        $attempt = PakistanFbrSubmissionAttempt::factory()->for($invoice, 'invoice')->create([
            'company_id' => $context['company']->id, 'submitted_by' => $context['user']->id,
            'idempotency_key' => 'original-lease-key', 'status' => FbrSubmissionStatus::Pending,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'claim_generation' => 1,
        ]);
        $invoice->update(['fbr_status' => FbrSubmissionStatus::Pending]);
        $url = "/api/v1/pakistan-fbr/invoices/{$invoice->id}/retry";
        $this->postJson($url, [], ['X-Company-Id' => $context['company']->id])->assertOk()->assertJsonPath('fbr_status', 'pending');
        $this->assertSame(0, $gateway->calls);
        $this->assertSame(1, $attempt->fresh()->claim_generation);

        $attempt->forceFill(['updated_at' => now()->subMinutes(3)])->save();
        $this->postJson($url, [], ['X-Company-Id' => $context['company']->id])->assertOk()->assertJsonPath('fbr_status', 'accepted');
        $this->assertSame(1, $gateway->calls);
        $this->assertSame('pkfbr:'.$invoice->id.':original-lease-key', $gateway->keys[0]);
        $this->assertSame(2, $attempt->fresh()->claim_generation);
        $this->assertNoAccountingEffects();
    }

    public function test_server_retry_rejects_changed_payload_and_configuration(): void
    {
        $context = $this->context();
        $invoice = $this->draft($context);
        $gateway = $this->gateway(true);
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'retry-hash'];
        $url = "/api/v1/pakistan-fbr/invoices/{$invoice->id}/retry";
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/submit", [], $headers)->assertServiceUnavailable();
        $configuration = FbrCompanyConfiguration::query()->where('company_id', $context['company']->id)->sole();
        $originalSeller = $configuration->seller_business_name;
        $configuration->update(['seller_business_name' => 'Changed seller']);
        $this->postJson($url, [], ['X-Company-Id' => $context['company']->id])->assertConflict();
        $configuration->update(['seller_business_name' => $originalSeller]);
        $invoice->update(['invoice_type' => 'Changed type']);
        $this->postJson($url, [], ['X-Company-Id' => $context['company']->id])->assertConflict();
        $invoice->update(['invoice_type' => 'Sale Invoice']);
        $this->postJson($url, [], ['X-Company-Id' => $context['company']->id])->assertOk();
        $this->assertSame(2, $gateway->calls);
        $this->assertNoAccountingEffects();
    }

    public function test_server_retry_is_permission_and_tenant_scoped_and_rejects_historical_documents(): void
    {
        $owner = $this->context();
        $invoice = $this->draft($owner);
        $gateway = $this->gateway(true);
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/submit", [], [
            'X-Company-Id' => $owner['company']->id, 'Idempotency-Key' => 'secured-retry',
        ])->assertServiceUnavailable();

        $outsider = $this->context();
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/retry", [], ['X-Company-Id' => $outsider['company']->id])->assertNotFound();
        $restricted = $this->stage3AccountingContext(['pakistan_fbr.view']);
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/retry", [], ['X-Company-Id' => $restricted['company']->id])->assertForbidden();
        Sanctum::actingAs($owner['user']);
        app(LegacyInvoiceImportService::class)->execute($this->legacySnapshot(), $owner['company'], $owner['user'], '4', hash('sha256', 'historical-retry'), 'historical-retry.json');
        $historical = PakistanFbrInvoice::query()->where('is_historical', true)->sole();
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$historical->id}/retry", [], ['X-Company-Id' => $owner['company']->id])->assertUnprocessable();
        $this->assertSame(1, $gateway->calls);
        $this->assertNoAccountingEffects();
    }

    public function test_historical_tax_amounts_and_disagreeing_header_are_preserved_with_exception(): void
    {
        $context = $this->context();
        $snapshot = $this->legacySnapshot();
        $snapshot['invoice_items'][0]['sales_tax'] = '18.01';
        $snapshot['invoice_items'][0]['extra_tax'] = '0.01';
        $snapshot['invoice_items'][0]['further_tax'] = '2.00';
        $snapshot['invoice_items'][0]['st_withheld'] = '5.00';
        $snapshot['invoice_items'][0]['amount'] = '120.02';

        app(LegacyInvoiceImportService::class)->execute($snapshot, $context['company'], $context['user'], '4', hash('sha256', 'historical-tax-discrepancy'), 'historical-tax.json');

        $invoice = PakistanFbrInvoice::query()->with('lines')->sole();
        $this->assertSame(11800, $invoice->total);
        $this->assertSame(1800, $invoice->sales_tax);
        $this->assertSame(1801, $invoice->lines->sole()->tax_amount);
        $this->assertSame('18.01', $invoice->lines->sole()->legacy_original_values['sales_tax']);
        $this->assertSame(12002, $invoice->lines->sole()->total);
        $this->assertSame(1, $invoice->lines->sole()->other_tax_amount);
        $this->assertSame(200, $invoice->lines->sole()->advance_tax_amount);
        $this->assertSame(500, $invoice->lines->sole()->withholding_tax_amount);
        $this->assertSame('118.00', $invoice->legacy_original_financial_values['total_amount']);
        $this->assertSame('SN001', $invoice->scenarioId());
        $this->assertSame(1, MigrationException::query()->where('exception_code', 'FINANCIAL_TAX_MISMATCH')->count());
        $this->assertSame(1, MigrationException::query()->where('exception_code', 'FINANCIAL_TOTAL_MISMATCH')->count());
        $this->assertNoAccountingEffects();
    }

    public function test_uncertified_submission_is_disabled_by_default_and_makes_no_provider_call(): void
    {
        $context = $this->context();
        $invoice = $this->draft($context);
        $gateway = $this->gateway();
        config(['services.fbr.pakistan_submission_enabled' => false]);

        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/submit", [], ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'disabled'])
            ->assertServiceUnavailable()->assertJsonPath('error_code', 'FBR_UNAVAILABLE');

        $this->assertSame(0, $gateway->calls);
        $this->assertDatabaseCount('pakistan_fbr_submission_attempts', 0);
    }

    public function test_accounting_and_pakistan_routes_cannot_resolve_each_others_documents_or_another_tenants_documents(): void
    {
        $context = $this->context();
        $pakistan = $this->draft($context);
        $accounting = Invoice::factory()->for($context['company'])->create(['customer_id' => $context['customer']->id, 'created_by' => $context['user']->id]);
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'wrong-domain'];
        $this->getJson("/api/v1/pakistan-fbr/invoices/{$accounting->id}", $headers)->assertNotFound();
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$accounting->id}/submit", [], $headers)->assertNotFound();
        $this->getJson("/api/v1/accounting/invoices/{$pakistan->id}", $headers)->assertNotFound();
        $this->getJson('/api/v1/accounting/invoices', $headers)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $accounting->id);

        $other = $this->context();
        $headers['X-Company-Id'] = $other['company']->id;
        $this->getJson("/api/v1/pakistan-fbr/invoices/{$pakistan->id}", $headers)->assertNotFound();
        $this->getJson("/api/v1/pakistan-fbr/invoices/{$pakistan->id}/attempts", $headers)->assertNotFound();
        $this->patchJson("/api/v1/pakistan-fbr/invoices/{$pakistan->id}", $this->payload(), $headers)->assertNotFound();
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$pakistan->id}/submit", [], $headers)->assertNotFound();
        $this->postJson('/api/v1/pakistan-fbr/invoices', [...$this->payload(), 'customer_id' => $context['customer']->id], $headers)->assertUnprocessable();
    }

    public function test_permissions_are_distinct_from_accounting_permissions(): void
    {
        $context = $this->stage3AccountingContext();
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'restricted'];
        $invoice = PakistanFbrInvoice::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);

        $this->getJson('/api/v1/pakistan-fbr/invoices', $headers)->assertForbidden();
        $this->postJson('/api/v1/pakistan-fbr/invoices', $this->payload(), $headers)->assertForbidden();
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/submit", [], $headers)->assertForbidden();
    }

    public function test_historical_document_remains_immutable_and_cannot_be_reclassified_by_normal_api(): void
    {
        $context = $this->context();
        app(LegacyInvoiceImportService::class)->execute($this->legacySnapshot(), $context['company'], $context['user'], '4', hash('sha256', 'isolated-history'), 'history.json');
        $invoice = PakistanFbrInvoice::query()->sole();
        $headers = ['X-Company-Id' => $context['company']->id];
        $this->getJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}", $headers)->assertOk()->assertJsonPath('document_state', 'HISTORICAL')->assertJsonMissingPath('migration_metadata');
        $this->patchJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}", [...$this->payload(), 'is_historical' => false], $headers)->assertUnprocessable();
        $this->expectException(\LogicException::class);
        $invoice->update(['is_historical' => false]);
    }

    public function test_new_domain_factories_have_valid_relationships(): void
    {
        $line = PakistanFbrInvoiceLine::factory()->create();
        $attempt = PakistanFbrSubmissionAttempt::factory()->create();
        $this->assertInstanceOf(PakistanFbrInvoice::class, $line->invoice);
        $this->assertInstanceOf(PakistanFbrInvoice::class, $attempt->invoice);
        $this->assertSame($attempt->invoice->company_id, $attempt->company_id);
    }

    public function test_pending_attempt_protects_the_lease_and_revalidates_company_configuration(): void
    {
        $context = $this->context();
        $invoice = $this->draft($context);
        $gateway = $this->gateway();
        $configuration = FbrCompanyConfiguration::query()->where('company_id', $context['company']->id)->sole();
        $payload = app(PakistanFbrPayloadMapper::class)->map($invoice->load('lines'), $configuration);
        PakistanFbrSubmissionAttempt::factory()->for($invoice, 'invoice')->create([
            'company_id' => $context['company']->id, 'submitted_by' => $context['user']->id,
            'idempotency_key' => 'in-flight', 'status' => FbrSubmissionStatus::Pending,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
        $invoice->update(['fbr_status' => FbrSubmissionStatus::Pending]);
        $url = "/api/v1/pakistan-fbr/invoices/{$invoice->id}/submit";
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'in-flight'];
        $this->postJson($url, [], $headers)->assertOk()->assertJsonPath('fbr_status', 'pending');
        $this->postJson($url, [], [...$headers, 'Idempotency-Key' => 'another'])->assertConflict();
        $configuration->update(['seller_business_name' => 'Changed seller']);
        $this->postJson($url, [], $headers)->assertConflict();
        $configuration->update(['credential' => null]);
        $this->postJson($url, [], $headers)->assertServiceUnavailable();
        $this->assertSame(0, $gateway->calls);
        $this->assertDatabaseCount('pakistan_fbr_submission_attempts', 1);
        $this->assertNoAccountingEffects();
    }

    /** @return array<string,mixed> */
    private function context(): array
    {
        return $this->stage3AccountingContext(['accounting.view', 'accounting.create', 'accounting.post', 'pakistan_fbr.view', 'pakistan_fbr.manage', 'pakistan_fbr.submit']);
    }

    /** @param array<string,mixed> $context */
    private function draft(array $context): PakistanFbrInvoice
    {
        config(['services.fbr.pakistan_submission_enabled' => true, 'services.fbr.endpoints.sandbox' => 'https://fbr.example.test/submit']);
        FbrCompanyConfiguration::factory()->for($context['company'])->create(['updated_by' => $context['user']->id]);
        $id = $this->postJson('/api/v1/pakistan-fbr/invoices', $this->payload(), ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => fake()->uuid()])->assertCreated()->json('id');

        return PakistanFbrInvoice::query()->findOrFail($id);
    }

    private function gateway(bool $failFirst = false): FbrGateway
    {
        $gateway = new class($failFirst) implements FbrGateway
        {
            public int $calls = 0;

            public array $keys = [];

            public function __construct(private readonly bool $failFirst) {}

            public function submit(array $payload, string $idempotencyKey, FbrSubmissionContext $context): FbrSubmissionResult
            {
                $this->keys[] = $idempotencyKey;
                $this->calls++;
                if ($this->failFirst && $this->calls === 1) {
                    throw new FbrUnavailableException('token=never-store-this');
                }

                return new FbrSubmissionResult(FbrSubmissionStatus::Accepted, 'PK-ACCEPTED', ['status' => 'accepted']);
            }
        };
        $this->app->instance(FbrGateway::class, $gateway);

        return $gateway;
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'invoice_date' => '2026-09-22', 'due_date' => '2026-10-22', 'invoice_type' => 'Sale Invoice',
            'sale_type' => 'Goods at standard rate (default)', 'origin_province' => 'SINDH', 'destination_province' => 'SINDH',
            'buyer_snapshot' => ['registration_number' => '1234567', 'name' => 'Buyer', 'type' => 'Registered', 'province' => 'SINDH', 'address' => 'Karachi'],
            'lines' => [['description' => 'Services', 'hs_code' => '9983.0000', 'fbr_rate_id' => '18%', 'quantity_milli' => 1000, 'unit' => 'unit', 'unit_price' => 10000, 'tax_rate_bps' => 1800]],
        ];
    }

    private function assertNoAccountingEffects(): void
    {
        foreach (['invoices', 'invoice_lines', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'inventory_transactions', 'bank_reconciliations', 'payroll_batches', 'supplier_bills', 'purchase_orders', 'outreach_messages', 'fbr_submission_attempts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
