<?php

namespace Tests\Feature\Stage12;

use App\Models\Account;
use App\Models\AiActionProposal;
use App\Models\CrmLead;
use App\Models\Customer;
use App\Models\EmailProviderConnection;
use App\Models\EmailSendingIdentity;
use App\Models\Journal;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AiActionProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_proposal_has_no_business_effect_before_approval_and_execution(): void
    {
        $context = $this->stage12AiContext();
        $customer = Customer::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $proposal = $this->propose($context, 'INVOICE_DRAFT', $this->invoicePayload($customer->id), 'invoice-proposal')->assertCreated()->assertJsonPath('status', 'PENDING')->assertJsonPath('impact_preview.summary', 'Create a draft invoice only; no GL or AR effect.');

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('journals', 0);
        $this->postJson('/api/v1/ai/action-proposals/'.$proposal->json('id').'/execute', [], $this->headers($context['company']->id, 'invoice-execute'))->assertConflict()->assertJsonPath('error_code', 'AI_ACTION_NOT_APPROVED');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_approved_invoice_execution_creates_only_draft_and_is_idempotent(): void
    {
        $context = $this->stage12AiContext();
        $customer = Customer::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $proposalId = $this->propose($context, 'INVOICE_DRAFT', $this->invoicePayload($customer->id), 'invoice-2')->json('id');

        $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/approve", [], $this->headers($context['company']->id))->assertOk()->assertJsonPath('status', 'APPROVED');
        $first = $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/execute", [], $this->headers($context['company']->id, 'execute-2'))->assertOk()->assertJsonPath('status', 'COMPLETED');
        $second = $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/execute", [], $this->headers($context['company']->id, 'execute-2'))->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseHas('invoices', ['company_id' => $context['company']->id, 'status' => 'draft', 'journal_id' => null]);
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'action' => 'ai_action_executed']);
    }

    public function test_rejected_proposal_cannot_execute_and_changes_nothing(): void
    {
        $context = $this->stage12AiContext();
        $proposalId = $this->propose($context, 'CRM_ACTIVITY_DRAFT', ['type' => 'TASK', 'subject' => 'Do not create', 'priority' => 'LOW'], 'reject-1')->json('id');

        $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/reject", ['reason' => 'Not required'], $this->headers($context['company']->id))->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/execute", [], $this->headers($context['company']->id, 'reject-execute'))->assertConflict()->assertJsonPath('error_code', 'AI_ACTION_NOT_APPROVED');

        $this->assertDatabaseCount('crm_activities', 0);
    }

    public function test_journal_proposal_rejects_unbalanced_payload_and_executes_as_unposted_draft(): void
    {
        $context = $this->stage12AiContext();
        $cash = Account::factory()->for($context['company'])->create(['code' => '1001', 'created_by' => $context['user']->id]);
        $revenue = Account::factory()->for($context['company'])->revenue()->create(['code' => '4001', 'created_by' => $context['user']->id]);
        $payload = ['posting_date' => '2026-09-25', 'description' => 'AI-prepared draft', 'lines' => [['account_id' => $cash->id, 'debit' => 100_00, 'credit' => 0], ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 100_00]]];

        $unbalanced = $payload;
        $unbalanced['lines'][1]['credit'] = 99_00;
        $this->propose($context, 'JOURNAL_DRAFT', $unbalanced, 'journal-bad')->assertUnprocessable()->assertJsonValidationErrors('lines');
        $proposalId = $this->propose($context, 'JOURNAL_DRAFT', $payload, 'journal-good')->assertCreated()->json('id');
        $this->approveAndExecute($context, $proposalId, 'journal-execute')->assertOk();

        $journal = Journal::query()->sole();
        $this->assertSame('draft', $journal->status);
        $this->assertNull($journal->posted_at);
        $this->assertSame(100_00, (int) $journal->lines()->sum('debit'));
        $this->assertSame(100_00, (int) $journal->lines()->sum('credit'));
    }

    public function test_purchase_order_proposal_executes_as_draft_without_ap_inventory_or_gl(): void
    {
        $context = $this->stage12AiContext();
        $expense = Account::factory()->for($context['company'])->expense()->create(['code' => '6001', 'created_by' => $context['user']->id]);
        $payable = Account::factory()->for($context['company'])->liability()->create(['code' => '2001', 'created_by' => $context['user']->id]);
        $supplier = Supplier::factory()->for($context['company'])->create(['created_by' => $context['user']->id, 'default_expense_account_id' => $expense->id, 'default_payable_account_id' => $payable->id]);
        $payload = ['supplier_id' => $supplier->id, 'order_date' => '2026-09-25', 'currency' => 'PKR', 'lines' => [['description' => 'Advisory', 'procurement_type' => 'service', 'quantity_milli' => 1000, 'unit' => 'service', 'unit_price' => 50_000, 'discount' => 0, 'tax_rate_bps' => 0, 'expense_account_id' => $expense->id]]];
        $proposalId = $this->propose($context, 'PURCHASE_ORDER_DRAFT', $payload, 'po-1')->assertCreated()->json('id');

        $this->approveAndExecute($context, $proposalId, 'po-execute')->assertOk();

        $this->assertDatabaseHas('purchase_orders', ['company_id' => $context['company']->id, 'status' => 'draft']);
        $this->assertDatabaseCount('supplier_bills', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_crm_activity_and_lead_update_require_separate_execution(): void
    {
        $context = $this->stage12AiContext();
        $lead = CrmLead::factory()->for($context['company'])->create(['first_name' => 'Old', 'status' => 'NEW', 'owner_id' => $context['user']->id, 'created_by' => $context['user']->id]);
        $activityId = $this->propose($context, 'CRM_ACTIVITY_DRAFT', ['related_type' => 'lead', 'related_id' => $lead->id, 'type' => 'TASK', 'subject' => 'Follow up', 'priority' => 'HIGH'], 'crm-task')->json('id');
        $updateId = $this->propose($context, 'CRM_LEAD_UPDATE', ['record_id' => $lead->id, 'changes' => ['first_name' => 'Updated', 'notes' => 'Reviewed by sales']], 'crm-update')->json('id');

        $this->assertSame('Old', $lead->fresh()->first_name);
        $this->approveAndExecute($context, $activityId, 'crm-task-execute')->assertOk();
        $this->approveAndExecute($context, $updateId, 'crm-update-execute')->assertOk();

        $this->assertDatabaseHas('crm_activities', ['company_id' => $context['company']->id, 'activityable_id' => $lead->id, 'subject' => 'Follow up']);
        $this->assertSame('Updated', $lead->fresh()->first_name);
    }

    public function test_outreach_proposal_creates_draft_sequence_without_enrollment_or_send(): void
    {
        $context = $this->stage12AiContext();
        $connection = EmailProviderConnection::factory()->for($context['company'])->create(['status' => 'CONNECTED', 'created_by' => $context['user']->id]);
        $identity = EmailSendingIdentity::factory()->for($context['company'])->for($connection, 'connection')->create(['verification_status' => 'VERIFIED', 'created_by' => $context['user']->id]);
        $payload = ['name' => 'AI draft sequence', 'sending_identity_id' => $identity->id, 'timezone' => 'Asia/Karachi', 'allowed_weekdays' => [1, 2, 3, 4, 5], 'send_window_start' => '09:00', 'send_window_end' => '17:00', 'steps' => [['type' => 'EMAIL', 'subject' => 'Hello', 'body_text' => 'Draft only', 'wait_minutes' => 0]]];
        $proposalId = $this->propose($context, 'OUTREACH_SEQUENCE_DRAFT', $payload, 'outreach-draft')->json('id');

        $this->approveAndExecute($context, $proposalId, 'outreach-execute')->assertOk();

        $this->assertDatabaseHas('outreach_sequences', ['company_id' => $context['company']->id, 'status' => 'DRAFT']);
        $this->assertDatabaseCount('outreach_enrollments', 0);
        $this->assertDatabaseCount('outreach_messages', 0);
    }

    public function test_proposal_records_are_invisible_across_tenants(): void
    {
        $first = $this->stage12AiContext();
        $proposalId = $this->propose($first, 'CRM_ACTIVITY_DRAFT', ['type' => 'TASK', 'subject' => 'Tenant one'], 'tenant-1')->json('id');
        $second = $this->stage12AiContext();

        $this->getJson("/api/v1/ai/action-proposals/{$proposalId}", $this->headers($second['company']->id))->assertNotFound();
        $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/approve", [], $this->headers($second['company']->id))->assertNotFound();
    }

    public function test_duplicate_proposal_key_is_idempotent_and_rejects_a_different_payload(): void
    {
        $context = $this->stage12AiContext();
        $payload = ['type' => 'TASK', 'subject' => 'Call customer', 'priority' => 'HIGH'];

        $first = $this->propose($context, 'CRM_ACTIVITY_DRAFT', $payload, 'same-proposal')->assertCreated();
        $second = $this->propose($context, 'CRM_ACTIVITY_DRAFT', $payload, 'same-proposal')->assertCreated();
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->propose($context, 'CRM_ACTIVITY_DRAFT', [...$payload, 'subject' => 'Different task'], 'same-proposal')->assertConflict();
        $this->assertDatabaseCount('ai_action_proposals', 1);
    }

    public function test_expired_proposal_cannot_be_approved_or_executed(): void
    {
        $context = $this->stage12AiContext();
        $proposalId = $this->propose($context, 'CRM_ACTIVITY_DRAFT', ['type' => 'TASK', 'subject' => 'Expired task'], 'expired-proposal')->json('id');
        AiActionProposal::query()->whereKey($proposalId)->update(['expires_at' => now()->subMinute()]);

        $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/approve", [], $this->headers($context['company']->id))->assertConflict()->assertJsonPath('error_code', 'AI_ACTION_EXPIRED');
        $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/execute", [], $this->headers($context['company']->id, 'expired-execute'))->assertConflict()->assertJsonPath('error_code', 'AI_ACTION_EXPIRED');
        $this->assertDatabaseCount('crm_activities', 0);
    }

    public function test_approval_rechecks_domain_permission(): void
    {
        $context = $this->stage12AiContext();
        $proposalId = $this->propose($context, 'CRM_ACTIVITY_DRAFT', ['type' => 'TASK', 'subject' => 'Permission check'], 'permission-proposal')->json('id');
        $context['user']->companies()->updateExistingPivot($context['company']->id, ['role_id' => null]);

        $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/approve", [], $this->headers($context['company']->id))->assertForbidden();
        $this->assertDatabaseCount('crm_activities', 0);
    }

    public function test_crm_update_proposal_rejects_fields_outside_the_server_allowlist(): void
    {
        $context = $this->stage12AiContext();
        $lead = CrmLead::factory()->for($context['company'])->create(['owner_id' => $context['user']->id, 'created_by' => $context['user']->id]);

        $this->propose($context, 'CRM_LEAD_UPDATE', ['record_id' => $lead->id, 'changes' => ['company_id' => '00000000-0000-0000-0000-000000000000']], 'unsafe-crm-update')->assertUnprocessable()->assertJsonValidationErrors('changes');

        $this->assertSame($context['company']->id, $lead->fresh()->company_id);
    }

    public function test_duplicate_approval_is_idempotent_and_does_not_execute_the_action(): void
    {
        $context = $this->stage12AiContext();
        $proposalId = $this->propose($context, 'CRM_ACTIVITY_DRAFT', ['type' => 'TASK', 'subject' => 'Approve once'], 'approve-twice')->json('id');

        $first = $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/approve", [], $this->headers($context['company']->id))->assertOk();
        $second = $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/approve", [], $this->headers($context['company']->id))->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame('APPROVED', $second->json('status'));
        $this->assertDatabaseCount('crm_activities', 0);
    }

    public function test_approval_revalidates_underlying_records_and_payload_integrity(): void
    {
        $context = $this->stage12AiContext();
        $customer = Customer::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $staleId = $this->propose($context, 'INVOICE_DRAFT', $this->invoicePayload($customer->id), 'stale-customer')->json('id');
        $customer->update(['is_active' => false]);

        $this->postJson("/api/v1/ai/action-proposals/{$staleId}/approve", [], $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('customer_id');

        $proposalId = $this->propose($context, 'CRM_ACTIVITY_DRAFT', ['type' => 'TASK', 'subject' => 'Original'], 'tampered-payload')->json('id');
        AiActionProposal::query()->findOrFail($proposalId)->update(['payload' => ['type' => 'TASK', 'subject' => 'Changed after proposal']]);
        $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/approve", [], $this->headers($context['company']->id))->assertConflict()->assertJsonPath('error_code', 'AI_ACTION_PAYLOAD_CHANGED');
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('crm_activities', 0);
    }

    /** @param array<string, mixed> $context */
    private function propose(array $context, string $type, array $payload, string $key): TestResponse
    {
        return $this->postJson('/api/v1/ai/action-proposals', ['action_type' => $type, 'payload' => $payload], $this->headers($context['company']->id, $key));
    }

    /** @param array<string, mixed> $context */
    private function approveAndExecute(array $context, string $proposalId, string $key): TestResponse
    {
        $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/approve", [], $this->headers($context['company']->id))->assertOk();

        return $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/execute", [], $this->headers($context['company']->id, $key));
    }

    /** @return array<string, mixed> */
    private function invoicePayload(string $customerId): array
    {
        return ['customer_id' => $customerId, 'invoice_date' => '2026-09-25', 'due_date' => '2026-10-25', 'currency' => 'PKR', 'lines' => [['description' => 'Consulting', 'quantity_milli' => 1000, 'unit' => 'service', 'unit_price' => 100_000, 'discount' => 0, 'tax_rate_bps' => 0, 'sales_type' => 'Services']]];
    }

    /** @return array<string, string> */
    private function headers(string $companyId, ?string $key = null): array
    {
        return array_filter(['X-Company-Id' => $companyId, 'Idempotency-Key' => $key]);
    }
}
