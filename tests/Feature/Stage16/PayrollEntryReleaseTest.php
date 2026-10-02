<?php

namespace Tests\Feature\Stage16;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Journal;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayrollEntryReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_releases_only_posted_payroll_once_with_audit_and_no_financial_effects(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $entry = $batch->entries()->firstOrFail();
        $this->grantRelease($context);
        $journalCount = Journal::query()->count();
        $lineCount = $batch->journal->lines()->count();
        $auditCount = AuditLog::query()->count();

        $first = $this->postJson($this->url($entry), ['released_at' => '2000-01-01T00:00:00Z', 'released_by' => 9999], $this->headers($context['company']))->assertOk();
        $second = $this->postJson($this->url($entry), [], $this->headers($context['company']))->assertOk();

        $this->assertSame($first->json(), $second->json());
        $this->assertNotSame('2000-01-01T00:00:00Z', $first->json('released_at'));
        $this->getJson('/api/v1/payroll/entries/'.$entry->id, $this->headers($context['company']))
            ->assertOk()->assertJsonPath('released_at', $first->json('released_at'))
            ->assertJsonPath('released_by', $context['user']->id);
        $this->assertSame($context['user']->id, $entry->fresh()->released_by);
        $this->assertNotNull($entry->fresh()->released_at);
        $this->assertSame($auditCount + 1, AuditLog::query()->count());
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'entity_id' => $entry->id, 'action' => 'employee_payslip_released']);
        $this->assertSame($journalCount, Journal::query()->count());
        $this->assertSame($lineCount, $batch->journal->lines()->count());
        $this->assertDatabaseCount('payroll_payments', 0);
        $this->assertDatabaseCount('payroll_payment_allocations', 0);
        $this->assertDatabaseCount('bank_transactions', 0);
    }

    public function test_returns_422_for_draft_calculated_reviewed_and_approved_entries(): void
    {
        $context = $this->stage8PayrollContext();
        $this->grantRelease($context);
        $batchId = $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id], $this->headers($context['company']))->assertCreated()->json('id');
        $entry = PayrollEntry::query()->where('payroll_batch_id', $batchId)->firstOrFail();

        foreach (['DRAFT', 'CALCULATED', 'REVIEWED', 'APPROVED'] as $status) {
            $this->postJson($this->url($entry), [], $this->headers($context['company']))
                ->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_NOT_POSTED');
            $this->assertNull($entry->fresh()->released_at);
            if ($status !== 'APPROVED') {
                $next = ['DRAFT' => 'calculate', 'CALCULATED' => 'review', 'REVIEWED' => 'approve'][$status];
                $this->postJson("/api/v1/payroll/batches/{$batchId}/{$next}", [], $this->headers($context['company']))->assertOk();
            }
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'employee_payslip_released']);
    }

    public function test_returns_422_for_posted_label_without_authoritative_posting_evidence(): void
    {
        $context = $this->stage8PayrollContext();
        $this->grantRelease($context);
        $batchId = $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id], $this->headers($context['company']))->assertCreated()->json('id');
        PayrollBatch::query()->findOrFail($batchId)->update(['status' => 'POSTED']);
        $entry = PayrollEntry::query()->where('payroll_batch_id', $batchId)->firstOrFail();

        $this->postJson($this->url($entry), [], $this->headers($context['company']))
            ->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_NOT_POSTED');
        $this->assertNull($entry->fresh()->released_at);
    }

    public function test_returns_403_without_dedicated_release_permission_even_with_payroll_administration(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $entry = $batch->entries()->firstOrFail();

        $this->postJson($this->url($entry), [], $this->headers($context['company']))->assertForbidden();
        $this->assertNull($entry->fresh()->released_at);
    }

    public function test_returns_404_for_foreign_entry_and_403_for_foreign_company_context(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $entry = $batch->entries()->firstOrFail();
        $this->grantRelease($context);
        $foreign = Company::factory()->create();

        $this->postJson($this->url($entry), [], $this->headers($foreign))->assertForbidden();
        $foreignUser = User::factory()->create();
        $role = Role::query()->create(['company_id' => $foreign->id, 'name' => 'Release only']);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'payroll.release']));
        CompanyUser::query()->create(['company_id' => $foreign->id, 'user_id' => $foreignUser->id, 'role_id' => $role->id, 'is_active' => true]);
        Sanctum::actingAs($foreignUser);

        $this->postJson($this->url($entry), [], $this->headers($foreign))->assertNotFound();
        $this->assertNull($entry->fresh()->released_at);
    }

    public function test_released_payslip_is_not_automatically_retracted_by_reversal(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $entry = $batch->entries()->firstOrFail();
        $this->grantRelease($context);
        $this->postJson($this->url($entry), [], $this->headers($context['company']))->assertOk();

        $this->postJson("/api/v1/payroll/batches/{$batch->id}/reverse", ['posting_date' => '2026-09-30', 'reason' => 'Correction required'], $this->headers($context['company'], 'reverse-release'))->assertOk();

        $this->assertSame('CANCELLED', $batch->fresh()->status);
        $this->assertNotNull($entry->fresh()->released_at);
        $this->postJson($this->url($entry), [], $this->headers($context['company']))->assertOk();
    }

    /** @param array<string, mixed> $context */
    private function postedBatch(array $context): PayrollBatch
    {
        $batchId = $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id], $this->headers($context['company']))->assertCreated()->json('id');
        foreach (['calculate', 'review', 'approve'] as $action) {
            $this->postJson("/api/v1/payroll/batches/{$batchId}/{$action}", [], $this->headers($context['company']))->assertOk();
        }
        $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($context['company'], 'post-'.$batchId))->assertOk();

        return PayrollBatch::query()->with('journal')->findOrFail($batchId);
    }

    /** @param array<string, mixed> $context */
    private function grantRelease(array $context): void
    {
        CompanyUser::query()->where('company_id', $context['company']->id)->where('user_id', $context['user']->id)->firstOrFail()
            ->role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'payroll.release']));
    }

    private function url(PayrollEntry $entry): string
    {
        return "/api/v1/payroll/entries/{$entry->id}/release";
    }

    /** @return array<string, string> */
    private function headers(Company $company, ?string $key = null): array
    {
        return array_filter(['X-Company-Id' => $company->id, 'Accept' => 'application/json', 'Idempotency-Key' => $key]);
    }
}
