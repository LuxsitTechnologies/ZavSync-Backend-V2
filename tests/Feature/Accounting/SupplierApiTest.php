<?php

namespace Tests\Feature\Accounting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_is_normalized_numbered_and_audited(): void
    {
        [, $company] = $this->actingAsCompanyUser(['suppliers.view', 'suppliers.manage']);

        $response = $this->postJson('/api/v1/accounting/payables/suppliers', $this->payload(['ntn' => '123-4567', 'cnic' => '35202-1234567-1']), ['X-Company-Id' => $company->id]);

        $response->assertCreated()->assertJsonPath('code', 'SUP-0001')->assertJsonPath('ntn', '1234567')->assertJsonPath('cnic', '3520212345671');
        $this->assertDatabaseHas('suppliers', ['company_id' => $company->id, 'code' => 'SUP-0001', 'ntn' => '1234567']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'create', 'module' => 'procurement']);
    }

    public function test_supplier_validation_duplicate_protection_and_permissions(): void
    {
        $context = $this->stage4AccountingContext();
        $headers = ['X-Company-Id' => $context['company']->id];
        $this->postJson('/api/v1/accounting/payables/suppliers', $this->payload(['ntn' => '123']), $headers)->assertUnprocessable()->assertJsonValidationErrors('ntn');
        $this->postJson('/api/v1/accounting/payables/suppliers', $this->payload(['ntn' => '1234567']), $headers)->assertCreated();
        $this->postJson('/api/v1/accounting/payables/suppliers', $this->payload(['name' => 'Duplicate NTN', 'ntn' => '1234567']), $headers)->assertUnprocessable()->assertJsonValidationErrors('ntn');

        [, $company] = $this->actingAsCompanyUser(['suppliers.view']);
        $this->postJson('/api/v1/accounting/payables/suppliers', $this->payload(), ['X-Company-Id' => $company->id])->assertForbidden();
    }

    public function test_supplier_isolation_and_history_deletion_protection(): void
    {
        $owner = $this->stage4AccountingContext();
        $outsider = $this->stage4AccountingContext();
        Sanctum::actingAs($outsider['user']);
        $this->getJson("/api/v1/accounting/payables/suppliers/{$owner['supplier']->id}", ['X-Company-Id' => $outsider['company']->id])->assertNotFound();

        Sanctum::actingAs($owner['user']);
        $this->postJson('/api/v1/purchases/orders', $this->orderPayload($owner), $this->headers($owner['company']->id, 'supplier-history'))->assertCreated();
        $this->deleteJson("/api/v1/accounting/payables/suppliers/{$owner['supplier']->id}", [], ['X-Company-Id' => $owner['company']->id])->assertUnprocessable();
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace(['name' => 'Stage Four Supplier', 'email' => 'payables@supplier.test', 'country' => 'PK', 'payment_terms_days' => 30, 'currency' => 'PKR', 'is_active' => true], $overrides);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function orderPayload(array $context): array
    {
        return ['supplier_id' => $context['supplier']->id, 'order_date' => '2026-09-22', 'currency' => 'PKR', 'lines' => [['description' => 'Services', 'procurement_type' => 'service', 'quantity_milli' => 1000, 'unit' => 'unit', 'unit_price' => 10000, 'tax_rate_bps' => 0, 'expense_account_id' => $context['accounts']['purchase_expense']->id]]];
    }

    /** @return array<string,string> */
    private function headers(string $companyId, string $key): array
    {
        return ['X-Company-Id' => $companyId, 'Idempotency-Key' => $key];
    }
}
