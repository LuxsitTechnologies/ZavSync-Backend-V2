<?php

namespace Tests\Feature\Stage15;

use App\Contracts\FbrCreditNoteGateway;
use App\Contracts\FbrGateway;
use App\Enums\FbrSubmissionStatus;
use App\Exceptions\FbrOutcomeUncertainException;
use App\Models\PakistanFbrCreditNote;
use App\Models\PakistanFbrCreditNoteLine;
use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrInvoiceLine;
use App\Services\Fbr\FbrSubmissionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PakistanFbrCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_full_draft_credit_is_derived_from_the_accepted_invoice_and_accounting_isolated(): void
    {
        $this->mock(FbrGateway::class)->shouldNotReceive('submit');
        $context = $this->stage3AccountingContext(['pakistan_fbr.view', 'pakistan_fbr.manage']);
        $invoice = PakistanFbrInvoice::factory()->for($context['company'])->create([
            'created_by' => $context['user']->id, 'document_state' => 'ISSUED', 'fbr_status' => FbrSubmissionStatus::Accepted,
            'fbr_reference_number' => 'PROVIDER-ACCEPTED',
        ]);
        $line = PakistanFbrInvoiceLine::factory()->for($invoice, 'invoice')->create();
        $path = "/api/v1/pakistan-fbr/invoices/{$invoice->id}/credit-notes";
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'Credit-A'];
        $payload = ['reason' => 'Customer requested a full reversal'];
        $first = $this->postJson($path, $payload, $headers)->assertCreated()
            ->assertJsonPath('total', 11800)->assertJsonPath('credit_note_number', 'PKFC-00000001')
            ->assertJsonPath('state', 'DRAFT')->assertJsonPath('provider_submission', 'NOT_CERTIFIED')
            ->assertJsonPath('lines.0.source_snapshot.quantity_milli', 1000)
            ->assertJsonPath('lines.0.source_snapshot.hs_code', '9983.0000')
            ->assertJsonPath('original_invoice_number', $invoice->invoice_number)
            ->assertJsonPath('original_fbr_reference_number', 'PROVIDER-ACCEPTED')->json('id');
        $this->postJson($path, $payload, $headers)->assertOk()->assertJsonPath('id', $first);
        $this->postJson($path, ['reason' => 'A different reason'], $headers)->assertConflict();
        $this->postJson($path, [...$payload, 'lines' => [['invoice_line_id' => $line->id, 'taxable_amount' => 1]]], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->getJson($path, $headers)->assertOk()->assertJsonPath('eligible', false)->assertJsonCount(1, 'data');
        $headers['Idempotency-Key'] = 'Credit-B';
        $this->postJson($path, $payload, $headers)
            ->assertConflict()->assertJsonPath('error_code', 'FBR_CREDIT_ALREADY_EXISTS');
        $this->assertDatabaseCount('pakistan_fbr_credit_notes', 1);
        foreach (['invoices', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_reconciliations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_draft_historical_and_foreign_company_documents_cannot_be_credited(): void
    {
        $context = $this->stage3AccountingContext(['pakistan_fbr.view', 'pakistan_fbr.manage']);
        $invoice = PakistanFbrInvoice::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $line = PakistanFbrInvoiceLine::factory()->for($invoice, 'invoice')->create();
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'blocked'];
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/credit-notes", ['reason' => 'Full reversal'], $headers)
            ->assertConflict()->assertJsonPath('error_code', 'FBR_CREDIT_SOURCE_NOT_ACCEPTED');
        $historical = PakistanFbrInvoice::factory()->for($context['company'])->create([
            'created_by' => $context['user']->id, 'document_state' => 'ISSUED', 'is_historical' => true,
            'fbr_status' => FbrSubmissionStatus::Accepted, 'fbr_reference_number' => 'HISTORICAL-EVIDENCE',
        ]);
        $historyLine = PakistanFbrInvoiceLine::factory()->for($historical, 'invoice')->create();
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$historical->id}/credit-notes", ['reason' => 'Full reversal'], $headers)
            ->assertConflict()->assertJsonPath('error_code', 'FBR_CREDIT_SOURCE_NOT_ACCEPTED');

        [$otherUser, $otherCompany] = $this->actingAsCompanyUser(['pakistan_fbr.view', 'pakistan_fbr.manage']);
        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/credit-notes", ['reason' => 'Full reversal'], [
            'X-Company-Id' => $otherCompany->id, 'Idempotency-Key' => 'foreign',
        ])->assertNotFound();
        $this->assertDatabaseCount('pakistan_fbr_credit_notes', 0);
    }

    public function test_source_must_be_issued_and_credit_recording_requires_dedicated_manage_permission(): void
    {
        $context = $this->stage3AccountingContext(['pakistan_fbr.view']);
        $invoice = PakistanFbrInvoice::factory()->for($context['company'])->create([
            'created_by' => $context['user']->id, 'document_state' => 'DRAFT',
            'fbr_status' => FbrSubmissionStatus::Accepted, 'fbr_reference_number' => 'INCONSISTENT-STATE',
        ]);
        $line = PakistanFbrInvoiceLine::factory()->for($invoice, 'invoice')->create();
        $path = "/api/v1/pakistan-fbr/invoices/{$invoice->id}/credit-notes";
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'Denied'];
        $this->getJson($path, $headers)->assertOk()->assertJsonPath('eligible', false);
        $this->postJson($path, ['reason' => 'Full reversal'], $headers)->assertForbidden();
        $invoice->update(['document_state' => 'ISSUED']);
        $this->postJson($path, ['reason' => 'Full reversal'], $headers)->assertForbidden();
        $this->assertDatabaseCount('pakistan_fbr_credit_notes', 0);
    }

    public function test_credit_factories_have_valid_fbr_only_relationships(): void
    {
        $credit = PakistanFbrCreditNote::factory()->create();
        $line = PakistanFbrCreditNoteLine::factory()->for($credit, 'creditNote')->create();

        $this->assertSame($credit->invoice_id, $line->invoiceLine->invoice_id);
        $this->assertSame($credit->company_id, $credit->invoice->company_id);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_credit_submission_is_disabled_without_a_certified_provider_and_does_not_claim(): void
    {
        $this->mock(FbrCreditNoteGateway::class)->shouldNotReceive('submit');
        $context = $this->stage3AccountingContext(['pakistan_fbr.view', 'pakistan_fbr.manage', 'pakistan_fbr.submit']);
        $invoice = PakistanFbrInvoice::factory()->for($context['company'])->create([
            'document_state' => 'ISSUED', 'fbr_status' => FbrSubmissionStatus::Accepted,
            'fbr_reference_number' => 'ORIGINAL-ACCEPTED',
        ]);
        PakistanFbrInvoiceLine::factory()->for($invoice, 'invoice')->create();
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'credit-draft'];
        $creditId = $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/credit-notes", ['reason' => 'Full customer reversal'], $headers)
            ->assertCreated()->json('id');

        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/credit-notes/{$creditId}/submit", [],
            ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'credit-submit'])
            ->assertStatus(503)->assertJsonPath('error_code', 'FBR_UNAVAILABLE');
        $this->assertDatabaseHas('pakistan_fbr_credit_notes', ['id' => $creditId, 'state' => 'DRAFT']);
        $this->assertDatabaseCount('pakistan_fbr_credit_note_attempts', 0);
    }

    public function test_fake_provider_acceptance_is_fenced_and_fully_credits_without_accounting_effects(): void
    {
        config()->set('services.fbr.credit_note_submission_enabled', true);
        $this->mock(FbrCreditNoteGateway::class)->shouldReceive('submit')->once()
            ->andReturn(new FbrSubmissionResult(FbrSubmissionStatus::Accepted, 'CREDIT-REFERENCE-1', ['status' => 'accepted']));
        $context = $this->stage3AccountingContext(['pakistan_fbr.view', 'pakistan_fbr.manage', 'pakistan_fbr.submit']);
        $invoice = PakistanFbrInvoice::factory()->for($context['company'])->create([
            'document_state' => 'ISSUED', 'fbr_status' => FbrSubmissionStatus::Accepted,
            'fbr_reference_number' => 'ORIGINAL-ACCEPTED',
        ]);
        PakistanFbrInvoiceLine::factory()->for($invoice, 'invoice')->create();
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'credit-draft'];
        $path = "/api/v1/pakistan-fbr/invoices/{$invoice->id}/credit-notes";
        $creditId = $this->postJson($path, ['reason' => 'Full customer reversal'], $headers)->assertCreated()->json('id');
        $submitPath = "$path/$creditId/submit";
        $submitHeaders = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'credit-submit'];

        $this->postJson($submitPath, [], $submitHeaders)->assertOk()
            ->assertJsonPath('state', 'ACCEPTED')->assertJsonPath('fully_credited', true)
            ->assertJsonPath('fbr_reference_number', 'CREDIT-REFERENCE-1');
        $this->postJson($submitPath, [], $submitHeaders)->assertOk()->assertJsonPath('state', 'ACCEPTED');
        $this->getJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}", $headers)
            ->assertOk()->assertJsonPath('fully_credited', true)->assertJsonPath('credit_note.id', $creditId);
        $this->patchJson("$path/$creditId", ['reason' => 'Alter accepted document'], $headers)->assertConflict();
        $this->deleteJson("$path/$creditId", [], $headers)->assertConflict();
        $this->assertDatabaseHas('pakistan_fbr_credit_note_attempts', ['credit_note_id' => $creditId, 'claim_generation' => 1, 'status' => 'accepted']);
        foreach (['invoices', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_reconciliations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_uncertain_fake_provider_result_locks_credit_until_authorized_evidence_reconciliation(): void
    {
        config()->set('services.fbr.credit_note_submission_enabled', true);
        $this->mock(FbrCreditNoteGateway::class)->shouldReceive('submit')->once()
            ->andThrow(new FbrOutcomeUncertainException('Provider result unknown.'));
        $context = $this->stage3AccountingContext(['pakistan_fbr.view', 'pakistan_fbr.manage', 'pakistan_fbr.submit', 'fbr.reconcile']);
        $invoice = PakistanFbrInvoice::factory()->for($context['company'])->create([
            'document_state' => 'ISSUED', 'fbr_status' => FbrSubmissionStatus::Accepted,
            'fbr_reference_number' => 'ORIGINAL-ACCEPTED',
        ]);
        PakistanFbrInvoiceLine::factory()->for($invoice, 'invoice')->create();
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'credit-draft'];
        $path = "/api/v1/pakistan-fbr/invoices/{$invoice->id}/credit-notes";
        $creditId = $this->postJson($path, ['reason' => 'Full customer reversal'], $headers)->assertCreated()->json('id');

        $this->postJson("$path/$creditId/submit", [], ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'credit-submit'])
            ->assertStatus(503);
        $this->patchJson("$path/$creditId", ['reason' => 'Unsafe change'], $headers)->assertConflict();
        $this->deleteJson("$path/$creditId", [], $headers)->assertConflict();
        $this->postJson("$path/$creditId/submit", [], ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'another-key'])
            ->assertConflict();
        $this->postJson("$path/$creditId/reconcile", [
            'outcome' => 'ACCEPTED', 'evidence_note' => 'Authoritative FBR record verified by administrator',
            'fbr_reference_number' => 'CREDIT-VERIFIED-1',
        ], ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'credit-reconcile'])->assertOk()->assertJsonPath('state', 'ACCEPTED');
        $this->postJson("$path/$creditId/reconcile", [
            'outcome' => 'ACCEPTED', 'evidence_note' => 'Authoritative FBR record verified by administrator',
            'fbr_reference_number' => 'CREDIT-VERIFIED-1',
        ], ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'credit-reconcile'])
            ->assertOk()->assertJsonPath('state', 'ACCEPTED');
        $this->assertDatabaseCount('pakistan_fbr_credit_note_reconciliations', 1);
        $this->assertDatabaseHas('pakistan_fbr_credit_notes', ['id' => $creditId, 'state' => 'ACCEPTED', 'fbr_reference_number' => 'CREDIT-VERIFIED-1']);
    }

    public function test_unsubmitted_credit_draft_can_be_deleted_and_a_new_numbered_draft_created(): void
    {
        $context = $this->stage3AccountingContext(['pakistan_fbr.view', 'pakistan_fbr.manage']);
        $invoice = PakistanFbrInvoice::factory()->for($context['company'])->create([
            'document_state' => 'ISSUED', 'fbr_status' => FbrSubmissionStatus::Accepted,
            'fbr_reference_number' => 'ORIGINAL-ACCEPTED',
        ]);
        PakistanFbrInvoiceLine::factory()->for($invoice, 'invoice')->create();
        $path = "/api/v1/pakistan-fbr/invoices/{$invoice->id}/credit-notes";
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'first-draft'];
        $first = $this->postJson($path, ['reason' => 'Original full reversal reason'], $headers)
            ->assertCreated()->json('id');

        $this->deleteJson("$path/$first", [], $headers)->assertOk()->assertJsonPath('deleted', true);
        $this->assertDatabaseCount('pakistan_fbr_credit_notes', 0);
        $this->assertDatabaseCount('pakistan_fbr_credit_note_lines', 0);
        $second = $this->postJson($path, ['reason' => 'Corrected full reversal reason'], [
            'X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'second-draft',
        ])->assertCreated()->assertJsonPath('credit_note_number', 'PKFC-00000002')->json('id');
        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_rejected_credit_can_correct_reason_and_resubmit_with_new_key_without_changing_full_reversal(): void
    {
        config()->set('services.fbr.credit_note_submission_enabled', true);
        $this->mock(FbrCreditNoteGateway::class)->shouldReceive('submit')->twice()
            ->andReturn(
                new FbrSubmissionResult(FbrSubmissionStatus::Rejected, null, ['status' => 'rejected']),
                new FbrSubmissionResult(FbrSubmissionStatus::Accepted, 'CREDIT-ACCEPTED-2', ['status' => 'accepted']),
            );
        $context = $this->stage3AccountingContext(['pakistan_fbr.view', 'pakistan_fbr.manage', 'pakistan_fbr.submit']);
        $invoice = PakistanFbrInvoice::factory()->for($context['company'])->create([
            'document_state' => 'ISSUED', 'fbr_status' => FbrSubmissionStatus::Accepted,
            'fbr_reference_number' => 'ORIGINAL-ACCEPTED',
        ]);
        PakistanFbrInvoiceLine::factory()->for($invoice, 'invoice')->create();
        $path = "/api/v1/pakistan-fbr/invoices/{$invoice->id}/credit-notes";
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'credit-draft'];
        $creditId = $this->postJson($path, ['reason' => 'Initial full reversal reason'], $headers)
            ->assertCreated()->json('id');
        $originalTotal = PakistanFbrCreditNote::query()->findOrFail($creditId)->total;

        $this->postJson("$path/$creditId/submit", [], [
            'X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'credit-submit-first',
        ])->assertOk()->assertJsonPath('state', 'REJECTED');
        $this->patchJson("$path/$creditId", ['reason' => 'Corrected full reversal reason'], $headers)
            ->assertOk()->assertJsonPath('reason', 'Corrected full reversal reason');
        $this->postJson("$path/$creditId/submit", [], [
            'X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'credit-submit-first',
        ])->assertConflict()->assertJsonPath('error_code', 'FBR_CREDIT_IDEMPOTENCY_CONFLICT');
        $this->postJson("$path/$creditId/submit", [], [
            'X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'credit-submit-second',
        ])->assertOk()->assertJsonPath('state', 'ACCEPTED')
            ->assertJsonPath('fbr_reference_number', 'CREDIT-ACCEPTED-2');
        $this->assertSame($originalTotal, PakistanFbrCreditNote::query()->findOrFail($creditId)->total);
        $this->assertDatabaseCount('pakistan_fbr_credit_note_attempts', 2);
        $this->assertDatabaseCount('journals', 0);
    }
}
