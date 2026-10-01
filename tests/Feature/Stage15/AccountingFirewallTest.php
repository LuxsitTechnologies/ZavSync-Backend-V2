<?php

namespace Tests\Feature\Stage15;

use App\Contracts\FbrGateway;
use App\Models\PakistanFbrInvoice;
use App\Services\Fbr\FbrSubmissionContext;
use App\Services\Fbr\FbrSubmissionResult;
use App\Services\Migration\LegacyInvoiceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyInvoiceSnapshots;
use Tests\TestCase;

class AccountingFirewallTest extends TestCase
{
    use BuildsLegacyInvoiceSnapshots, RefreshDatabase;

    public function test_historical_import_and_blocked_actions_create_zero_financial_inventory_operational_or_provider_effects(): void
    {
        $context = $this->stage3AccountingContext(['accounting.view', 'accounting.create', 'accounting.edit', 'accounting.post', 'pakistan_fbr.view', 'pakistan_fbr.manage', 'pakistan_fbr.submit']);
        $gateway = new class implements FbrGateway
        {
            public int $calls = 0;

            public function submit(array $payload, string $idempotencyKey, FbrSubmissionContext $context): FbrSubmissionResult
            {
                $this->calls++;

                throw new \RuntimeException('The historical firewall failed.');
            }
        };
        $this->app->instance(FbrGateway::class, $gateway);
        app(LegacyInvoiceImportService::class)->execute($this->legacySnapshot(), $context['company'], $context['user'], '4', hash('sha256', 'firewall'), 'firewall.json');
        $invoice = PakistanFbrInvoice::query()->sole();

        $this->postJson("/api/v1/accounting/invoices/{$invoice->id}/post", [], ['X-Company-Id' => $context['company']->id])->assertNotFound();
        $this->postJson("/api/v1/accounting/fbr/invoices/{$invoice->id}/submit", [], ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'historical-block'])->assertNotFound();
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/submit", [], ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'historical-block'])->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->patchJson("/api/v1/accounting/invoices/{$invoice->id}", [], ['X-Company-Id' => $context['company']->id])->assertUnprocessable();
        $this->deleteJson("/api/v1/accounting/invoices/{$invoice->id}", [], ['X-Company-Id' => $context['company']->id])->assertNotFound();
        $this->getJson('/api/v1/accounting/invoices', ['X-Company-Id' => $context['company']->id])->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/accounting/receivables/invoices', ['X-Company-Id' => $context['company']->id])->assertOk()->assertExactJson([]);

        $this->assertSame(0, $gateway->calls);
        foreach (['invoices', 'invoice_lines', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'inventory_transactions', 'bank_reconciliations', 'payroll_batches', 'payroll_entries', 'purchase_orders', 'supplier_bills', 'outreach_messages', 'fbr_submission_attempts', 'pakistan_fbr_submission_attempts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
