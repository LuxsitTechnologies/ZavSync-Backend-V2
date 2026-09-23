<?php

namespace Tests\Feature\Accounting;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Stage4FactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_stage_four_factories_create_persisted_models(): void
    {
        $this->assertModelExists(Supplier::factory()->create());
        $this->assertModelExists(PurchaseOrder::factory()->create());
        $this->assertModelExists(PurchaseOrderLine::factory()->create());
        $this->assertModelExists(PurchaseReceipt::factory()->create());
        $this->assertModelExists(PurchaseReceiptLine::factory()->create());
        $this->assertModelExists(SupplierBill::factory()->create());
        $this->assertModelExists(SupplierBillLine::factory()->create());
        $this->assertModelExists(SupplierPayment::factory()->create());
        $this->assertModelExists(SupplierPaymentAllocation::factory()->create());
    }
}
