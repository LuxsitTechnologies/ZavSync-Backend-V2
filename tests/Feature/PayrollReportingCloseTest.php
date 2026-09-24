<?php

namespace Tests\Feature;

use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollReportingCloseTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_summary_employee_history_components_and_payslip_use_snapshots(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $entry = PayrollEntry::query()->where('payroll_batch_id', $batch->id)->firstOrFail();
        $context['employee']->update(['full_name' => 'Renamed After Posting']);
        $context['profile']->update(['base_salary' => 9_999_999]);

        $this->getJson("/api/v1/payroll/reports/register/{$batch->id}", $this->headers($context['company']->id))->assertOk()->assertJsonPath('data.entries.0.employee_name', 'Payroll Employee')->assertJsonPath('data.entries.0.net_pay', 910_000);
        $this->getJson('/api/v1/payroll/reports/summary?from=2026-09-01&to=2026-09-30', $this->headers($context['company']->id))->assertOk()->assertJsonPath('data.totals.gross_earnings', 1_100_000)->assertJsonPath('data.totals.employer_total_cost', 1_140_000);
        $this->getJson("/api/v1/payroll/reports/employees/{$context['employee']->id}", $this->headers($context['company']->id))->assertOk()->assertJsonPath('data.0.employee_name', 'Payroll Employee');
        $this->getJson('/api/v1/payroll/reports/components', $this->headers($context['company']->id))->assertOk()->assertJsonFragment(['component_code' => 'ALW', 'total_amount' => 100_000]);
        $this->getJson("/api/v1/payroll/entries/{$entry->id}/payslip", $this->headers($context['company']->id))->assertOk()->assertJsonPath('data.employee.full_name', 'Payroll Employee')->assertJsonPath('data.base_salary', 1_000_000)->assertJsonPath('data.statutory_rule_snapshot.0.version', '2026-test')->assertJsonFragment(['component_name' => 'Allowance']);
    }

    public function test_liability_report_is_operational_detail_while_gl_reconciliation_is_authoritative(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);

        $liabilities = $this->getJson("/api/v1/payroll/reports/liabilities?payroll_batch_id={$batch->id}", $this->headers($context['company']->id))->assertOk();
        $this->assertSame(1_140_000, $liabilities->json('data.outstanding_total'));
        $this->assertEqualsCanonicalizing(['EMPLOYEE_CONTRIBUTION', 'EMPLOYER_CONTRIBUTION', 'NET_PAY', 'OTHER_DEDUCTION', 'TAX'], collect($liabilities->json('data.totals'))->pluck('liability_type')->all());

        $reconciliation = $this->getJson("/api/v1/payroll/reports/reconciliation/{$batch->id}", $this->headers($context['company']->id))->assertOk();
        $reconciliation->assertJsonPath('data.liability_gl_balance', 1_140_000)->assertJsonPath('data.operational_outstanding', 1_140_000)->assertJsonPath('data.liability_difference', 0)->assertJsonPath('data.liabilities_reconciled', true)->assertJsonPath('data.balanced', true);
    }

    public function test_payment_and_statutory_settlement_keep_operational_liabilities_reconciled_to_gl(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $this->postJson("/api/v1/payroll/batches/{$batch->id}/payments", ['financial_account_id' => $context['bank']->id, 'payment_date' => '2026-09-30', 'amount' => 200_000], $this->headers($context['company']->id, 'recon-payment'))->assertCreated();
        $this->postJson('/api/v1/payroll/liability-settlements', ['payroll_batch_id' => $batch->id, 'liability_type' => 'TAX', 'financial_account_id' => $context['bank']->id, 'payment_date' => '2026-09-30', 'amount' => 10_000], $this->headers($context['company']->id, 'recon-tax'))->assertCreated();

        $this->getJson("/api/v1/payroll/reports/reconciliation/{$batch->id}", $this->headers($context['company']->id))->assertOk()->assertJsonPath('data.operational_outstanding', 930_000)->assertJsonPath('data.liability_gl_balance', 930_000)->assertJsonPath('data.liability_difference', 0)->assertJsonPath('data.liabilities_reconciled', true);
    }

    public function test_approved_unposted_payroll_blocks_period_close(): void
    {
        $context = $this->stage8PayrollContext();
        $batchId = $this->approvedBatch($context);

        $response = $this->getJson("/api/v1/accounting/periods/{$context['accountingPeriod']->id}/readiness", $this->headers($context['company']->id))->assertOk();
        $check = collect($response->json('checks'))->firstWhere('key', 'approved_unposted_payroll');
        $this->assertFalse($response->json('ready'));
        $this->assertFalse($check['passed']);
        $this->assertSame(1, $check['value']);
        $this->postJson("/api/v1/accounting/periods/{$context['accountingPeriod']->id}/close", ['idempotency_key' => 'period-close-blocked'], $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->assertDatabaseHas('payroll_batches', ['id' => $batchId, 'status' => 'APPROVED']);
    }

    public function test_posted_payroll_with_unsettled_liabilities_is_informational_not_a_close_blocker(): void
    {
        $context = $this->stage8PayrollContext();
        $this->postedBatch($context);

        $response = $this->getJson("/api/v1/accounting/periods/{$context['accountingPeriod']->id}/readiness", $this->headers($context['company']->id))->assertOk();
        $payrollCheck = collect($response->json('checks'))->firstWhere('key', 'outstanding_payroll_liabilities');
        $integrity = collect($response->json('checks'))->firstWhere('key', 'payroll_integrity');
        $this->assertSame('INFORMATION', $payrollCheck['severity']);
        $this->assertTrue($payrollCheck['passed']);
        $this->assertSame(1, $payrollCheck['value']);
        $this->assertTrue($integrity['passed']);
        $this->assertTrue($response->json('ready'));
    }

    public function test_posted_status_without_a_journal_is_detected_as_a_close_integrity_failure(): void
    {
        $context = $this->stage8PayrollContext();
        PayrollBatch::factory()->for($context['company'])->for($context['payrollPeriod'], 'period')->create(['status' => 'POSTED', 'accounting_date' => '2026-09-30', 'journal_id' => null, 'created_by' => $context['user']->id]);

        $response = $this->getJson("/api/v1/accounting/periods/{$context['accountingPeriod']->id}/readiness", $this->headers($context['company']->id))->assertOk();
        $check = collect($response->json('checks'))->firstWhere('key', 'payroll_integrity');
        $this->assertFalse($check['passed']);
        $this->assertSame(1, $check['value']);
        $this->assertFalse($response->json('ready'));
    }

    /** @param array<string, mixed> $context */
    private function approvedBatch(array $context): string
    {
        $batchId = $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id], $this->headers($context['company']->id))->assertCreated()->json('id');
        foreach (['calculate', 'review', 'approve'] as $action) {
            $this->postJson("/api/v1/payroll/batches/{$batchId}/{$action}", [], $this->headers($context['company']->id))->assertOk();
        }

        return $batchId;
    }

    /** @param array<string, mixed> $context */
    private function postedBatch(array $context): PayrollBatch
    {
        $batchId = $this->approvedBatch($context);
        $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($context['company']->id, 'post-'.$batchId))->assertOk();

        return PayrollBatch::query()->findOrFail($batchId);
    }

    /** @return array<string, string> */
    private function headers(string $companyId, ?string $idempotencyKey = null): array
    {
        return array_filter(['X-Company-Id' => $companyId, 'Accept' => 'application/json', 'Idempotency-Key' => $idempotencyKey]);
    }
}
