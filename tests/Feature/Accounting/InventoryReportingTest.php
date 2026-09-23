<?php

namespace Tests\Feature\Accounting;

use App\Services\Inventory\InventoryReportingService;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ledger_valuation_fifo_layers_low_stock_and_reconciliation_are_authoritative(): void
    {
        $context = $this->stage5AccountingContext();
        $context['item']->update(['reorder_level_milli' => 25000]);
        $inventory = app(InventoryService::class);
        $inventory->adjust($context['company']->id, $context['user'], $this->adjustment($context, 10000, 1000, '2026-09-10'), 'report-1');
        $inventory->adjust($context['company']->id, $context['user'], $this->adjustment($context, 10000, 2000, '2026-09-20'), 'report-2');
        $reports = app(InventoryReportingService::class);

        $ledger = $reports->ledger($context['company']->id, ['item_id' => $context['item']->id]);
        $this->assertCount(2, $ledger);
        $this->assertSame(10000, $ledger[0]['running_quantity']);
        $this->assertSame(30000, $ledger[1]['running_value']);
        $filteredLedger = $reports->ledger($context['company']->id, ['from' => '2026-09-15', 'search' => $context['item']->sku]);
        $this->assertCount(1, $filteredLedger);
        $this->assertSame(20000, $filteredLedger[0]['running_quantity']);
        $this->assertSame(30000, $filteredLedger[0]['running_value']);

        $historical = $reports->valuation($context['company']->id, ['as_of' => '2026-09-15'])->first();
        $current = $reports->valuation($context['company']->id, [])->first();
        $this->assertSame(10000, $historical['quantity_on_hand_milli']);
        $this->assertSame(10000, $historical['inventory_value']);
        $this->assertSame(20000, $current['quantity_on_hand_milli']);
        $this->assertSame(30000, $current['inventory_value']);
        $this->assertCount(2, $reports->layers($context['company']->id, ['item_id' => $context['item']->id]));
        $this->assertSame('low_stock', $reports->lowStock($context['company']->id)->first()['status']);

        $reconciliation = $reports->reconciliation($context['company']->id, []);
        $this->assertSame(30000, $reconciliation['inventory_valuation']);
        $this->assertSame(30000, $reconciliation['gl_inventory_balance']);
        $this->assertSame(0, $reconciliation['difference']);
        $this->assertSame('reconciled', $reconciliation['status']);
    }

    public function test_report_endpoints_return_warehouse_totals_and_filter_company_data(): void
    {
        $context = $this->stage5AccountingContext();
        app(InventoryService::class)->adjust($context['company']->id, $context['user'], $this->adjustment($context, 5000, 1200, '2026-09-12'), 'endpoint-report');
        $headers = ['X-Company-Id' => $context['company']->id, 'Accept' => 'application/json'];

        $this->getJson('/api/v1/accounting/inventory/valuation?warehouse_id='.$context['warehouse']->id, $headers)
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.quantity_on_hand_milli', 5000)->assertJsonPath('0.inventory_value', 6000);
        $this->getJson('/api/v1/accounting/inventory/ledger?item_id='.$context['item']->id, $headers)
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.warehouse_id', $context['warehouse']->id);
        $this->getJson('/api/v1/accounting/inventory/items/'.$context['item']->id.'/layers', $headers)
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.remaining_value', 6000);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function adjustment(array $context, int $quantity, int $unitCost, string $date): array
    {
        return ['item_id' => $context['item']->id, 'warehouse_id' => $context['warehouse']->id, 'quantity_milli' => $quantity, 'unit_cost' => $unitCost, 'direction' => 'positive', 'transaction_date' => $date, 'reason' => 'Report test'];
    }
}
