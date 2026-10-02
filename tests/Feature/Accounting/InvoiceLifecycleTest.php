<?php

namespace Tests\Feature\Accounting;

use App\Enums\InvoiceStatus;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_uses_authoritative_integer_calculation_and_does_not_affect_gl_or_ar(): void
    {
        $context = $this->stage3AccountingContext();
        $payload = $this->payload($context['customer']->id);
        $payload['subtotal'] = 1;
        $payload['total'] = 1;

        $response = $this->postJson('/api/v1/accounting/invoices', $payload, $this->headers($context['company']->id, 'invoice-create-1'));

        $response->assertCreated()->assertJsonPath('accounting_status', 'draft')->assertJsonPath('subtotal', 15000)->assertJsonPath('discount', 1000)->assertJsonPath('taxable_amount', 14000)->assertJsonPath('sales_tax', 2520)->assertJsonPath('total', 16520);
        $this->assertDatabaseCount('journals', 0);
        $this->getJson('/api/v1/accounting/receivables/invoices', ['X-Company-Id' => $context['company']->id])->assertOk()->assertExactJson([]);
    }

    public function test_same_creation_key_is_idempotent_and_different_payload_returns_409(): void
    {
        $context = $this->stage3AccountingContext();
        $headers = $this->headers($context['company']->id, 'invoice-repeat');

        $first = $this->postJson('/api/v1/accounting/invoices', $this->payload($context['customer']->id), $headers)->assertCreated();
        $this->postJson('/api/v1/accounting/invoices', $this->payload($context['customer']->id), $headers)->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->postJson('/api/v1/accounting/invoices', $this->payload($context['customer']->id, ['notes' => 'Different']), $headers)->assertConflict();
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_lines', 1);
    }

    public function test_posted_invoice_creates_balanced_journal_with_receivable_revenue_and_sales_tax(): void
    {
        $context = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($context);

        $response = $this->postJson("/api/v1/accounting/invoices/$invoiceId/post", [], ['X-Company-Id' => $context['company']->id]);

        $response->assertOk()->assertJsonPath('accounting_status', 'unpaid')->assertJsonPath('journal_id', fn ($value) => is_string($value));
        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->assertSame(16520, (int) $invoice->journal->lines()->sum('debit'));
        $this->assertSame(16520, (int) $invoice->journal->lines()->sum('credit'));
        $this->assertDatabaseHas('journal_lines', ['journal_id' => $invoice->journal_id, 'account_id' => $context['accounts']['accounts_receivable']->id, 'debit' => 16520, 'credit' => 0]);
        $this->assertDatabaseHas('journal_lines', ['journal_id' => $invoice->journal_id, 'account_id' => $context['accounts']['sales_revenue']->id, 'debit' => 0, 'credit' => 14000]);
        $this->assertDatabaseHas('journal_lines', ['journal_id' => $invoice->journal_id, 'account_id' => $context['accounts']['sales_tax_payable']->id, 'debit' => 0, 'credit' => 2520]);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $invoiceId, 'action' => 'post', 'module' => 'invoicing']);
    }

    public function test_invoice_cannot_create_duplicate_financial_effect_when_posted_twice(): void
    {
        $context = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($context);

        $first = $this->postJson("/api/v1/accounting/invoices/$invoiceId/post", [], ['X-Company-Id' => $context['company']->id])->assertOk();
        $second = $this->postJson("/api/v1/accounting/invoices/$invoiceId/post", [], ['X-Company-Id' => $context['company']->id])->assertOk();

        $this->assertSame($first->json('journal_id'), $second->json('journal_id'));
        $this->assertDatabaseCount('journals', 1);
        $this->assertDatabaseCount('journal_lines', 3);
    }

    public function test_locked_period_rejects_posting_and_rolls_back_invoice_state(): void
    {
        $context = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($context);
        $context['company']->invoices()->findOrFail($invoiceId);
        AccountingPeriod::query()->where('company_id', $context['company']->id)->update(['status' => 'closed']);

        $this->postJson("/api/v1/accounting/invoices/$invoiceId/post", [], ['X-Company-Id' => $context['company']->id])->assertUnprocessable()->assertJsonValidationErrors('posting_date');

        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'status' => InvoiceStatus::Draft->value, 'journal_id' => null]);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_posted_financial_fields_cannot_be_edited_or_deleted(): void
    {
        $context = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($context);
        $this->postJson("/api/v1/accounting/invoices/$invoiceId/post", [], ['X-Company-Id' => $context['company']->id])->assertOk();

        $this->patchJson("/api/v1/accounting/invoices/$invoiceId", $this->payload($context['customer']->id, ['notes' => 'Attempt']), ['X-Company-Id' => $context['company']->id])->assertUnprocessable();
        $this->deleteJson("/api/v1/accounting/invoices/$invoiceId", [], ['X-Company-Id' => $context['company']->id])->assertUnprocessable();

        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'status' => InvoiceStatus::Unpaid->value]);
    }

    public function test_void_reverses_posted_invoice_instead_of_deleting_it(): void
    {
        $context = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($context);
        $this->postJson("/api/v1/accounting/invoices/$invoiceId/post", [], ['X-Company-Id' => $context['company']->id])->assertOk();

        $response = $this->postJson("/api/v1/accounting/invoices/$invoiceId/void", ['posting_date' => '2026-09-23', 'reason' => 'Customer contract cancelled'], ['X-Company-Id' => $context['company']->id]);

        $response->assertOk()->assertJsonPath('accounting_status', 'void')->assertJsonPath('balance_due', 0);
        $this->assertDatabaseCount('journals', 2);
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'status' => InvoiceStatus::Void->value]);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $invoiceId, 'action' => 'void']);
    }

    public function test_cross_company_invoice_access_and_posting_return_404(): void
    {
        $owner = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($owner);
        $outsider = $this->stage3AccountingContext();
        Sanctum::actingAs($outsider['user']);
        $headers = ['X-Company-Id' => $outsider['company']->id];

        $this->getJson("/api/v1/accounting/invoices/$invoiceId", $headers)->assertNotFound();
        $this->patchJson("/api/v1/accounting/invoices/$invoiceId", $this->payload($outsider['customer']->id), $headers)->assertNotFound();
        $this->postJson("/api/v1/accounting/invoices/$invoiceId/post", [], $headers)->assertNotFound();
    }

    public function test_account_mapping_rejects_another_company_account(): void
    {
        $context = $this->stage3AccountingContext();
        $other = $this->stage3AccountingContext();
        Sanctum::actingAs($context['user']);

        $this->patchJson('/api/v1/accounting/settings/account-mappings/bank', ['account_id' => $other['accounts']['bank']->id], ['X-Company-Id' => $context['company']->id])->assertUnprocessable()->assertJsonValidationErrors('account_id');

        $this->assertDatabaseHas('account_mappings', ['company_id' => $context['company']->id, 'key' => 'bank', 'account_id' => $context['accounts']['bank']->id]);
    }

    public function test_per_kg_quantity_non_standard_tax_and_invoice_above_one_million_pkr_are_calculated_without_floats(): void
    {
        $context = $this->stage3AccountingContext();
        $payload = $this->payload($context['customer']->id, ['lines' => [[
            'item_name' => 'Bulk material', 'description' => 'Per-kilogram sale', 'quantity_milli' => 1250,
            'unit' => 'kg', 'unit_price' => 100000001, 'discount' => 0, 'tax_rate_bps' => 750,
            'other_tax_rate_bps' => 0, 'advance_tax_rate_bps' => 0, 'withholding_tax_rate_bps' => 0,
            'sales_type' => 'Standardized Goods',
        ]]]);

        $response = $this->postJson('/api/v1/accounting/invoices', $payload, $this->headers($context['company']->id, 'invoice-large-per-kg'));

        $response->assertCreated()
            ->assertJsonPath('subtotal', 125000001)
            ->assertJsonPath('sales_tax', 9375000)
            ->assertJsonPath('total', 134375001)
            ->assertJsonPath('lines.0.quantity_milli', 1250)
            ->assertJsonPath('lines.0.tax_rate_bps', 750);
    }

    public function test_missing_account_mapping_rolls_back_invoice_posting(): void
    {
        $context = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($context);
        AccountMapping::query()->where('company_id', $context['company']->id)->where('key', 'sales_revenue')->delete();

        $this->postJson("/api/v1/accounting/invoices/$invoiceId/post", [], ['X-Company-Id' => $context['company']->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_mappings');

        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'status' => InvoiceStatus::Draft->value, 'journal_id' => null]);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_invoice_numbering_is_sequential_within_each_company(): void
    {
        $first = $this->stage3AccountingContext();
        $firstNumber = $this->postJson('/api/v1/accounting/invoices', $this->payload($first['customer']->id), $this->headers($first['company']->id, 'company-one-invoice'))
            ->assertCreated()
            ->json('invoice_number');
        $second = $this->stage3AccountingContext();
        $secondNumber = $this->postJson('/api/v1/accounting/invoices', $this->payload($second['customer']->id), $this->headers($second['company']->id, 'company-two-invoice'))
            ->assertCreated()
            ->json('invoice_number');

        $this->actingAs($first['user']);
        $nextNumber = $this->postJson('/api/v1/accounting/invoices', $this->payload($first['customer']->id), $this->headers($first['company']->id, 'company-one-next-invoice'))
            ->assertCreated()
            ->json('invoice_number');

        $this->assertSame('INV-2026-0001', $firstNumber);
        $this->assertSame('INV-2026-0001', $secondNumber);
        $this->assertSame('INV-2026-0002', $nextNumber);
    }

    public function test_invoice_detail_contains_authoritative_print_data(): void
    {
        $context = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($context);

        $response = $this->getJson("/api/v1/accounting/invoices/$invoiceId", ['X-Company-Id' => $context['company']->id]);

        $response->assertOk()
            ->assertJsonPath('company.id', $context['company']->id)
            ->assertJsonPath('customer.id', $context['customer']->id)
            ->assertJsonPath('lines.0.description', 'Implementation services')
            ->assertJsonPath('lines.0.quantity_milli', 1500)
            ->assertJsonPath('lines.0.tax_amount', 2520)
            ->assertJsonPath('total', 16520)
            ->assertJsonPath('fbr_status', 'not_submitted');
    }

    public function test_stage_three_write_actions_enforce_company_permissions(): void
    {
        $context = $this->stage3AccountingContext(['accounting.view']);
        $invoice = Invoice::factory()->for($context['company'])->for($context['customer'])->create(['created_by' => $context['user']->id]);
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'permission-check'];

        $this->postJson('/api/v1/accounting/customers', [], $headers)->assertForbidden();
        $this->postJson('/api/v1/accounting/invoices', $this->payload($context['customer']->id), $headers)->assertForbidden();
        $this->postJson("/api/v1/accounting/invoices/{$invoice->id}/post", [], $headers)->assertForbidden();
        $this->postJson("/api/v1/accounting/fbr/invoices/{$invoice->id}/submit", [], $headers)->assertForbidden();
        $this->postJson("/api/v1/accounting/receivables/invoices/{$invoice->id}/payments", [
            'amount' => 1000, 'payment_date' => '2026-09-23', 'method' => 'bank_transfer',
            'bank_account_id' => $context['accounts']['bank']->id,
        ], $headers)->assertForbidden();
        $this->patchJson('/api/v1/accounting/settings/account-mappings/bank', ['account_id' => $context['accounts']['bank']->id], $headers)->assertForbidden();
    }

    /** @param array<string, mixed> $context */
    private function createDraft(array $context): string
    {
        return (string) $this->postJson('/api/v1/accounting/invoices', $this->payload($context['customer']->id), $this->headers($context['company']->id, 'create-'.fake()->uuid()))->assertCreated()->json('id');
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(string $customerId, array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $customerId, 'invoice_date' => '2026-09-22', 'due_date' => '2026-10-22', 'currency' => 'PKR',
            'notes' => null, 'terms' => 'Due within 30 days', 'lines' => [[
                'item_name' => 'Consulting', 'description' => 'Implementation services', 'quantity_milli' => 1500,
                'unit' => 'hour', 'unit_price' => 10000, 'discount' => 1000, 'tax_rate_bps' => 1800,
                'other_tax_rate_bps' => 0, 'advance_tax_rate_bps' => 0, 'withholding_tax_rate_bps' => 0,
                'sales_type' => 'Standardized Goods',
            ]],
        ], $overrides);
    }

    /** @return array<string, string> */
    private function headers(string $companyId, string $key): array
    {
        return ['X-Company-Id' => $companyId, 'Idempotency-Key' => $key];
    }
}
