<?php

namespace Tests\Feature\Stage15;

use App\Contracts\FbrGateway;
use App\Models\Company;
use App\Models\FbrSubmissionAttempt;
use App\Models\Invoice;
use App\Models\LegacyFbrEvidence;
use App\Models\LegacyImportRun;
use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrSubmissionAttempt;
use App\Models\User;
use App\Services\Migration\LegacyInvoiceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsLegacyInvoiceSnapshots;
use Tests\TestCase;

class CertificationDateTimeTest extends TestCase
{
    use BuildsLegacyInvoiceSnapshots, RefreshDatabase;

    public function test_dates_and_original_timestamp_precision_survive_timezone_boundaries(): void
    {
        Http::preventStrayRequests();
        $this->mock(FbrGateway::class)->shouldNotReceive('submit');
        $this->travelTo('2026-10-01 23:59:59 UTC');
        $snapshot = $this->legacySnapshot();
        $snapshot['invoices'][0]['issue_date'] = '2026-01-01';
        $snapshot['invoices'][0]['due_date'] = '2026-01-02';
        $snapshot['invoices'][0]['created_at'] = '2026-01-01T00:00:00.123456+05:00';
        $snapshot['invoice_items'][0]['created_at'] = '2025-12-31T23:59:59.999999-04:00';
        $snapshot['fbr_invoice_submissions'][0]['last_attempt_at'] = '2026-01-01T00:00:00.123456+05:00';
        app(LegacyInvoiceImportService::class)->execute($snapshot, Company::factory()->create(), User::factory()->create(), '4', hash('sha256', 'date-boundaries'), 'synthetic.json');
        $invoice = PakistanFbrInvoice::query()->sole();
        $this->assertSame('2026-01-01', $invoice->invoice_date->format('Y-m-d'));
        $this->assertSame('2026-01-02', $invoice->due_date->format('Y-m-d'));
        $this->assertSame('2026-01-01T00:00:00.123456+05:00', $invoice->legacy_original_timestamps['created_at']);
        $this->assertSame('2025-12-31T23:59:59.999999-04:00', $invoice->lines()->sole()->legacy_original_values['created_at']);
        $this->assertSame('2026-01-01T00:00:00.123456+05:00', LegacyFbrEvidence::query()->sole()->original_timestamps['last_attempt_at']);
        $this->assertSame('2026-10-01 23:59:59', LegacyImportRun::query()->sole()->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 23:59:59', LegacyImportRun::query()->sole()->completed_at->format('Y-m-d H:i:s'));
        $native = Invoice::factory()->create(['invoice_date' => '2026-01-01', 'due_date' => '2026-01-02']);
        $this->assertSame('2026-01-01', $native->fresh()->invoice_date->format('Y-m-d'));
        $this->assertSame('2026-01-02', $native->fresh()->due_date->format('Y-m-d'));
        foreach ([FbrSubmissionAttempt::class, PakistanFbrSubmissionAttempt::class] as $model) {
            $attempt = $model::factory()->create(['completed_at' => now()]);
            $this->assertSame('2026-10-01 23:59:59', $attempt->fresh()->created_at->format('Y-m-d H:i:s'));
            $this->assertSame('2026-10-01 23:59:59', $attempt->fresh()->completed_at->format('Y-m-d H:i:s'));
        }
        Http::assertNothingSent();
    }
}
