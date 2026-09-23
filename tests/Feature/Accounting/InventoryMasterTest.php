<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\InventoryConsumption;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\InventoryTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_master_create_update_deactivate_and_duplicate_sku(): void
    {
        $context = $this->stage5AccountingContext();
        $payload = $this->itemPayload($context['accounts']);
        $headers = $this->headers($context['company']->id);

        $created = $this->postJson('/api/v1/inventory/items', $payload, $headers)->assertCreated()
            ->assertJsonPath('sku', 'STAGE5-001')->assertJsonPath('track_inventory', true);
        $itemId = $created->json('id');
        $this->putJson("/api/v1/inventory/items/$itemId", [...$payload, 'name' => 'Updated item', 'is_active' => false], $headers)
            ->assertOk()->assertJsonPath('name', 'Updated item')->assertJsonPath('is_active', false);
        $this->postJson('/api/v1/inventory/items', $payload, $headers)->assertUnprocessable()->assertJsonValidationErrors('sku');

        $this->assertDatabaseHas('inventory_items', ['id' => $itemId, 'company_id' => $context['company']->id, 'is_active' => false]);
        $this->assertSame(2, AuditLog::query()->where('company_id', $context['company']->id)->where('module', 'inventory')->count());
    }

    public function test_item_accounts_and_tenant_access_are_company_scoped(): void
    {
        $context = $this->stage5AccountingContext();
        $otherCompany = Company::factory()->create();
        $foreignAccount = Account::factory()->for($otherCompany)->create(['created_by' => $context['user']->id]);
        $payload = $this->itemPayload($context['accounts']);
        $payload['inventory_asset_account_id'] = $foreignAccount->id;

        $this->postJson('/api/v1/inventory/items', $payload, $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('inventory_asset_account_id');
        [, $otherCompany] = $this->actingAsCompanyUser(['inventory.view', 'inventory.manage']);
        $this->getJson("/api/v1/inventory/items/{$context['item']->id}", $this->headers($otherCompany->id))->assertNotFound();
    }

    public function test_warehouse_crud_default_selection_and_company_isolation(): void
    {
        $context = $this->stage5AccountingContext();
        $headers = $this->headers($context['company']->id);
        $created = $this->postJson('/api/v1/inventory/warehouses', ['code' => 'SECOND', 'name' => 'Second Warehouse', 'location' => 'Lahore', 'is_active' => true, 'is_default' => true], $headers)
            ->assertCreated()->assertJsonPath('is_default', true);
        $warehouseId = $created->json('id');
        $this->assertFalse($context['warehouse']->fresh()->is_default);
        $this->putJson("/api/v1/inventory/warehouses/$warehouseId", ['code' => 'SECOND', 'name' => 'Second Warehouse', 'location' => 'Lahore', 'is_active' => false, 'is_default' => false], $headers)
            ->assertOk()->assertJsonPath('is_active', false);
        $this->assertDatabaseHas('warehouses', ['id' => $warehouseId, 'company_id' => $context['company']->id, 'is_active' => false]);
        $this->assertSame(2, AuditLog::query()->where('company_id', $context['company']->id)->where('module', 'inventory')->count());

        [, $otherCompany] = $this->actingAsCompanyUser(['inventory.view']);
        $this->getJson("/api/v1/inventory/warehouses/$warehouseId", $this->headers($otherCompany->id))->assertNotFound();
    }

    public function test_stage_five_factories_create_company_consistent_inventory_records(): void
    {
        $context = $this->stage5AccountingContext();
        $transaction = InventoryTransaction::factory()->for($context['company'])->create([
            'created_by' => $context['user']->id,
        ]);
        $inbound = InventoryMovement::factory()->create([
            'company_id' => $context['company']->id,
            'inventory_transaction_id' => $transaction->id,
            'item_id' => $context['item']->id,
            'warehouse_id' => $context['warehouse']->id,
            'created_by' => $context['user']->id,
        ]);
        $layer = InventoryLayer::factory()->create([
            'company_id' => $context['company']->id,
            'item_id' => $context['item']->id,
            'warehouse_id' => $context['warehouse']->id,
            'source_movement_id' => $inbound->id,
        ]);
        $outboundTransaction = InventoryTransaction::factory()->for($context['company'])->create([
            'created_by' => $context['user']->id,
            'type' => 'sale_issue',
        ]);
        $outbound = InventoryMovement::factory()->create([
            'company_id' => $context['company']->id,
            'inventory_transaction_id' => $outboundTransaction->id,
            'item_id' => $context['item']->id,
            'warehouse_id' => $context['warehouse']->id,
            'type' => 'sale_issue',
            'quantity_in_milli' => 0,
            'quantity_out_milli' => 1000,
            'value_delta' => -10000,
            'created_by' => $context['user']->id,
        ]);
        $consumption = InventoryConsumption::factory()->create([
            'company_id' => $context['company']->id,
            'outbound_movement_id' => $outbound->id,
            'inventory_layer_id' => $layer->id,
        ]);

        $this->assertSame($context['company']->id, $transaction->company_id);
        $this->assertSame($context['item']->id, $layer->item_id);
        $this->assertSame($context['warehouse']->id, $inbound->warehouse_id);
        $this->assertSame($outbound->id, $consumption->outbound_movement_id);
        $this->assertSame($layer->id, $consumption->inventory_layer_id);
    }

    /** @param array<string,Account> $accounts @return array<string,mixed> */
    private function itemPayload(array $accounts): array
    {
        return [
            'sku' => 'stage5-001', 'name' => 'Stage 5 Item', 'type' => 'inventory', 'track_inventory' => true,
            'unit' => 'unit', 'sales_unit' => 'unit', 'purchase_unit' => 'unit', 'category' => 'Hardware',
            'is_active' => true, 'sales_price' => 15000, 'default_purchase_cost' => 10000,
            'reorder_level_milli' => 5000, 'reorder_quantity_milli' => 10000,
            'inventory_asset_account_id' => $accounts['inventory_asset']->id, 'cogs_account_id' => $accounts['cogs']->id,
            'sales_account_id' => $accounts['sales_revenue']->id, 'inventory_adjustment_account_id' => $accounts['inventory_adjustment']->id,
        ];
    }

    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId, 'Accept' => 'application/json'];
    }
}
