<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\BankReconciliationMatch;
use App\Models\BankStatementImport;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\CustomerPayment;
use App\Models\GatewaySettlement;
use App\Models\GatewaySettlementAllocation;
use App\Models\InternalTransfer;
use App\Models\Journal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankingAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_account_crud_default_and_audit(): void
    {
        $context = $this->stage6BankingContext();
        $gl = Account::factory()->for($context['company'])->create(['code' => '1030', 'created_by' => $context['user']->id]);
        $payload = ['name' => 'Secondary Bank', 'type' => 'bank', 'bank_name' => 'Meezan', 'account_title' => 'ZavSync', 'masked_account_number' => '****1234', 'iban' => 'PK00 TEST 1234', 'currency' => 'pkr', 'gl_account_id' => $gl->id, 'opening_balance' => null, 'is_default' => true, 'is_active' => true, 'notes' => null];
        $headers = $this->headers($context['company']->id);

        $created = $this->postJson('/api/v1/banking/accounts', $payload, $headers)->assertCreated()->assertJsonPath('currency', 'PKR')->assertJsonPath('is_default', true);
        $id = $created->json('id');
        $this->assertFalse($context['bank']->fresh()->is_default);
        $this->putJson("/api/v1/banking/accounts/{$id}", [...$payload, 'name' => 'Updated Bank', 'is_active' => false], $headers)->assertOk()->assertJsonPath('is_active', false);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'module' => 'banking', 'action' => 'deactivate']);
    }

    public function test_gl_mapping_and_tenant_access_are_scoped(): void
    {
        $context = $this->stage6BankingContext();
        $other = Company::factory()->create();
        $foreign = Account::factory()->for($other)->create(['created_by' => $context['user']->id]);
        $payload = ['name' => 'Invalid', 'type' => 'bank', 'bank_name' => 'Bank', 'currency' => 'PKR', 'gl_account_id' => $foreign->id, 'is_default' => false, 'is_active' => true];

        $this->postJson('/api/v1/banking/accounts', $payload, $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('gl_account_id');
        [, $outsider] = $this->actingAsCompanyUser(['banking.view']);
        $this->getJson("/api/v1/banking/accounts/{$context['bank']->id}", $this->headers($outsider->id))->assertNotFound();
    }

    public function test_rbac_denies_account_management(): void
    {
        $context = $this->stage6BankingContext(['banking.view']);
        $gl = Account::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $this->postJson('/api/v1/banking/accounts', ['name' => 'Denied', 'type' => 'bank', 'bank_name' => 'Bank', 'currency' => 'PKR', 'gl_account_id' => $gl->id, 'is_default' => false, 'is_active' => true], $this->headers($context['company']->id))->assertForbidden();
    }

    public function test_stage_six_factories_create_banking_records(): void
    {
        $context = $this->stage6BankingContext();
        $import = BankStatementImport::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'imported_by' => $context['user']->id]);
        $transaction = BankTransaction::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'bank_statement_import_id' => $import->id, 'created_by' => $context['user']->id]);
        $journal = Journal::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $transfer = InternalTransfer::factory()->create(['company_id' => $context['company']->id, 'source_financial_account_id' => $context['bank']->id, 'destination_financial_account_id' => $context['cash']->id, 'journal_id' => $journal->id, 'created_by' => $context['user']->id]);
        $settlement = GatewaySettlement::factory()->create(['company_id' => $context['company']->id, 'destination_financial_account_id' => $context['bank']->id, 'clearing_account_id' => $context['accounts']['gateway_clearing']->id, 'fee_account_id' => $context['accounts']['gateway_fees']->id, 'created_by' => $context['user']->id]);
        $payment = CustomerPayment::factory()->create(['company_id' => $context['company']->id, 'customer_id' => $context['customer']->id, 'bank_account_id' => $context['accounts']['bank']->id, 'created_by' => $context['user']->id]);
        $allocation = GatewaySettlementAllocation::factory()->create(['company_id' => $context['company']->id, 'gateway_settlement_id' => $settlement->id, 'source_id' => $payment->id]);
        $match = BankReconciliationMatch::factory()->create(['company_id' => $context['company']->id, 'bank_transaction_id' => $transaction->id, 'matchable_id' => $journal->id, 'matched_by' => $context['user']->id]);

        $this->assertSame($context['company']->id, $import->company_id);
        $this->assertSame($journal->id, $transfer->journal_id);
        $this->assertSame($settlement->id, $allocation->gateway_settlement_id);
        $this->assertSame($transaction->id, $match->bank_transaction_id);
    }

    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId, 'Accept' => 'application/json'];
    }
}
