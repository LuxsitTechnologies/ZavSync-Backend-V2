<?php

namespace Tests\Feature\Accounting;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_authoritative_integer_calculation_idempotency_and_no_financial_effect(): void
    {
        $context = $this->stage4AccountingContext();
        $payload = $this->payload($context);
        $headers = $this->headers($context['company']->id, 'po-idempotent');

        $first = $this->postJson('/api/v1/purchases/orders', $payload, $headers)->assertCreated()
            ->assertJsonPath('subtotal', 15000)->assertJsonPath('discount', 1000)->assertJsonPath('tax', 2520)->assertJsonPath('total', 16520);
        $this->postJson('/api/v1/purchases/orders', $payload, $headers)->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->postJson('/api/v1/purchases/orders', [...$payload, 'notes' => 'different'], $headers)->assertConflict();
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('supplier_bills', 0);
    }

    public function test_submit_approve_and_reject_workflow_enforces_state_and_permissions(): void
    {
        $context = $this->stage4AccountingContext();
        $id = $this->create($context, 'po-workflow');

        $this->postJson("/api/v1/purchases/orders/$id/submit", ['note' => 'Review'], ['X-Company-Id' => $context['company']->id])->assertOk()->assertJsonPath('status', 'pending');
        $this->postJson("/api/v1/purchases/orders/$id/approve", ['note' => 'Approved'], ['X-Company-Id' => $context['company']->id])->assertOk()->assertJsonPath('status', 'approved');
        $this->patchJson("/api/v1/purchases/orders/$id", $this->payload($context), ['X-Company-Id' => $context['company']->id])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $id, 'action' => 'approve']);

        $limited = $this->stage4AccountingContext(['purchase_orders.view']);
        $this->postJson("/api/v1/purchases/orders/$id/approve", [], ['X-Company-Id' => $limited['company']->id])->assertForbidden();
    }

    public function test_pending_order_can_be_rejected_and_cancelled_but_received_order_cannot(): void
    {
        $context = $this->stage4AccountingContext();
        $id = $this->create($context, 'po-reject');
        $headers = ['X-Company-Id' => $context['company']->id];
        $this->postJson("/api/v1/purchases/orders/$id/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/purchases/orders/$id/reject", ['note' => 'Needs correction'], $headers)->assertOk()->assertJsonPath('status', 'rejected');
        $this->postJson("/api/v1/purchases/orders/$id/cancel", ['reason' => 'No longer required'], $headers)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertDatabaseHas('purchase_orders', ['id' => $id, 'status' => PurchaseOrderStatus::Cancelled->value]);
    }

    /** @param array<string,mixed> $context */
    private function create(array $context, string $key): string
    {
        return (string) $this->postJson('/api/v1/purchases/orders', $this->payload($context), $this->headers($context['company']->id, $key))->assertCreated()->json('id');
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function payload(array $context): array
    {
        return ['supplier_id' => $context['supplier']->id, 'order_date' => '2026-09-22', 'expected_delivery_date' => '2026-09-30', 'currency' => 'PKR', 'lines' => [['description' => 'Implementation service', 'procurement_type' => 'service', 'quantity_milli' => 1500, 'unit' => 'hour', 'unit_price' => 10000, 'discount' => 1000, 'tax_rate_bps' => 1800, 'expense_account_id' => $context['accounts']['purchase_expense']->id]]];
    }

    /** @return array<string,string> */
    private function headers(string $companyId, string $key): array
    {
        return ['X-Company-Id' => $companyId, 'Idempotency-Key' => $key];
    }
}
