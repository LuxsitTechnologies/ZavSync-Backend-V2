<?php

namespace Tests\Feature\Accounting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventorySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_permissions_are_enforced_per_capability(): void
    {
        $context = $this->stage5AccountingContext(['inventory.view']);
        $headers = ['X-Company-Id' => $context['company']->id, 'Accept' => 'application/json', 'Idempotency-Key' => 'denied'];
        $payload = ['item_id' => $context['item']->id, 'warehouse_id' => $context['warehouse']->id, 'quantity_milli' => 1000, 'unit_cost' => 1000, 'direction' => 'positive', 'transaction_date' => '2026-09-15', 'reason' => 'Denied'];

        $this->getJson('/api/v1/inventory/items', $headers)->assertOk();
        $this->postJson('/api/v1/inventory/adjustments', $payload, $headers)->assertForbidden();
        $this->postJson('/api/v1/inventory/transfers', $payload, $headers)->assertForbidden();
        $this->getJson('/api/v1/accounting/inventory/reconciliation', $headers)->assertForbidden();
    }

    public function test_cross_company_item_and_warehouse_references_are_rejected(): void
    {
        $first = $this->stage5AccountingContext();
        $second = $this->stage5AccountingContext();
        $headers = ['X-Company-Id' => $second['company']->id, 'Accept' => 'application/json', 'Idempotency-Key' => 'cross-company'];
        $payload = [
            'item_id' => $first['item']->id, 'warehouse_id' => $first['warehouse']->id,
            'quantity_milli' => 1000, 'unit_cost' => 1000, 'direction' => 'positive',
            'transaction_date' => '2026-09-15', 'reason' => 'Cross company',
        ];

        $this->postJson('/api/v1/inventory/adjustments', $payload, $headers)->assertUnprocessable()->assertJsonValidationErrors(['item_id', 'warehouse_id']);
        $this->getJson('/api/v1/accounting/inventory/ledger', $headers)->assertOk()->assertJsonCount(0);
    }
}
