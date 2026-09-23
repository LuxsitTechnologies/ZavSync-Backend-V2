<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_payload_creates_company_customer_normalizes_identifiers_and_audits(): void
    {
        [, $company] = $this->actingAsCompanyUser(['accounting.create', 'accounting.view']);

        $response = $this->postJson('/api/v1/accounting/customers', $this->payload(['ntn' => '123-4567', 'cnic' => '35202-1234567-1']), ['X-Company-Id' => $company->id]);

        $response->assertCreated()->assertJsonPath('ntn', '1234567')->assertJsonPath('cnic', '3520212345671')->assertJsonPath('code', 'CUS-0001');
        $this->assertDatabaseHas('customers', ['company_id' => $company->id, 'name' => 'Acme Pakistan', 'ntn' => '1234567', 'cnic' => '3520212345671']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'create', 'module' => 'accounts_receivable']);
    }

    public function test_returns_422_for_invalid_ntn_and_cnic(): void
    {
        [, $company] = $this->actingAsCompanyUser(['accounting.create']);

        $this->postJson('/api/v1/accounting/customers', $this->payload(['ntn' => '123456', 'cnic' => '123']), ['X-Company-Id' => $company->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ntn', 'cnic']);

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_cross_company_customer_read_update_and_delete_return_404(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.view', 'accounting.edit']);
        $other = $this->stage3AccountingContext()['customer'];
        $this->actingAs($user);

        $headers = ['X-Company-Id' => $company->id];
        $this->getJson("/api/v1/accounting/customers/{$other->id}", $headers)->assertNotFound();
        $this->patchJson("/api/v1/accounting/customers/{$other->id}", $this->payload(), $headers)->assertNotFound();
        $this->deleteJson("/api/v1/accounting/customers/{$other->id}", [], $headers)->assertNotFound();
    }

    public function test_customer_with_invoice_cannot_be_deleted_and_can_be_deactivated(): void
    {
        $context = $this->stage3AccountingContext();
        $invoice = Invoice::factory()->for($context['company'])->for($context['customer'])->create(['created_by' => $context['user']->id]);

        $this->deleteJson("/api/v1/accounting/customers/{$context['customer']->id}", [], ['X-Company-Id' => $context['company']->id])->assertConflict();
        $response = $this->patchJson("/api/v1/accounting/customers/{$context['customer']->id}", $this->payload(['is_active' => false, 'code' => $context['customer']->code]), ['X-Company-Id' => $context['company']->id]);

        $response->assertOk()->assertJsonPath('is_active', false);
        $this->assertModelExists($invoice);
    }

    public function test_customer_numbering_is_sequential_and_company_scoped(): void
    {
        [, $firstCompany] = $this->actingAsCompanyUser(['accounting.create']);
        $first = $this->postJson('/api/v1/accounting/customers', $this->payload(['ntn' => '1234567']), ['X-Company-Id' => $firstCompany->id])->assertCreated();
        $second = $this->postJson('/api/v1/accounting/customers', $this->payload(['name' => 'Second Customer', 'ntn' => '7654321']), ['X-Company-Id' => $firstCompany->id])->assertCreated();
        [, $secondCompany] = $this->actingAsCompanyUser(['accounting.create']);
        $other = $this->postJson('/api/v1/accounting/customers', $this->payload(['name' => 'Other Company Customer']), ['X-Company-Id' => $secondCompany->id])->assertCreated();

        $this->assertSame('CUS-0001', $first->json('code'));
        $this->assertSame('CUS-0002', $second->json('code'));
        $this->assertSame('CUS-0001', $other->json('code'));
    }

    public function test_valid_account_mapping_change_is_audited(): void
    {
        $context = $this->stage3AccountingContext();
        $replacement = Account::factory()->for($context['company'])->create(['code' => '1030', 'name' => 'Receipts Bank', 'created_by' => $context['user']->id]);

        $this->patchJson('/api/v1/accounting/settings/account-mappings/bank', ['account_id' => $replacement->id], ['X-Company-Id' => $context['company']->id])
            ->assertOk()
            ->assertJsonPath('account_id', $replacement->id);

        $this->assertDatabaseHas('account_mappings', ['company_id' => $context['company']->id, 'key' => 'bank', 'account_id' => $replacement->id]);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'action' => 'update', 'module' => 'accounting_configuration']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Acme Pakistan', 'legal_name' => 'Acme Pakistan (Private) Limited', 'type' => 'business',
            'ntn' => '1234567', 'cnic' => null, 'strn' => 'STRN-1', 'email' => 'accounts@acme.test', 'phone' => '+92 300 1234567',
            'billing_address' => 'Main Boulevard', 'city' => 'Lahore', 'province' => 'Punjab', 'country' => 'PK',
            'postal_code' => '54000', 'contact_person' => 'Finance Manager', 'payment_terms_days' => 30,
            'credit_limit' => 50000000, 'currency' => 'PKR', 'tax_metadata' => ['registered' => true], 'is_active' => true, 'notes' => null,
        ], $overrides);
    }
}
