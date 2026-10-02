<?php

namespace Tests\Feature\Stage15;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceLine;
use App\Models\PakistanFbrInvoiceLine;
use App\Models\User;
use App\Services\Accounting\InvoiceService;
use App\Services\Fbr\PakistanFbrInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceCreationRollbackTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{bool}> */
    public static function domains(): array
    {
        return ['native' => [true], 'FBR' => [false]];
    }

    #[DataProvider('domains')]
    public function test_line_failure_rolls_back_draft_and_does_not_consume_number(bool $native): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $data = ['customer_id' => Customer::factory()->for($company)->create()->id,
            'invoice_date' => '2026-10-02', 'due_date' => '2026-11-02', 'currency' => 'PKR', 'sale_type' => 'Goods',
            'buyer_snapshot' => ['name' => 'Synthetic buyer'],
            'lines' => [['description' => 'Synthetic service', 'quantity_milli' => 1000, 'unit' => 'unit', 'unit_price' => 10000, 'tax_rate_bps' => 1800, 'sales_type' => 'Goods']]];
        $service = $native ? InvoiceService::class : PakistanFbrInvoiceService::class;
        $event = 'eloquent.created: '.($native ? InvoiceLine::class : PakistanFbrInvoiceLine::class);
        Event::listen($event, static function (): void {
            throw new \RuntimeException('Injected line failure');
        });
        try {
            app($service)->create($company->id, $user, $data, 'rollback-key');
            $this->fail('Line failure must abort draft.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected line failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        foreach (['invoices', 'invoice_lines', 'pakistan_fbr_invoices', 'pakistan_fbr_invoice_lines', 'journals', 'journal_lines'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $invoice = app($service)->create($company->id, $user, $data, 'rollback-key');
        $this->assertSame(1, $invoice->sequence);
        $this->assertSame(1, $invoice->lines()->count());
    }
}
