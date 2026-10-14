<?php

namespace App\Services\Fbr;

use App\Exceptions\PlatformException;
use App\Models\Company;
use App\Models\PakistanFbrCreditNote;
use App\Models\PakistanFbrInvoice;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PakistanFbrCreditNoteService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function create(string $companyId, User $user, PakistanFbrInvoice $invoice, array $data, string $key): PakistanFbrCreditNote
    {
        return DB::transaction(function () use ($companyId, $user, $invoice, $data, $key): PakistanFbrCreditNote {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);
            $document = PakistanFbrInvoice::query()->where('company_id', $companyId)->with('lines')->lockForUpdate()->findOrFail($invoice->id);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = PakistanFbrCreditNote::query()->where('company_id', $companyId)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->invoice_id !== $document->id || ! hash_equals($existing->payload_hash, $hash)) {
                    throw new ConflictHttpException('The credit-note idempotency key belongs to a different request.');
                }

                return $existing->load('lines');
            }
            if (PakistanFbrCreditNote::query()->where('invoice_id', $document->id)->exists()) {
                throw new PlatformException('FBR_CREDIT_ALREADY_EXISTS', 'This FBR Invoice already has its one full credit note.', 409);
            }
            if (! $document->canCreateInternalCreditNote() || $document->lines->isEmpty()) {
                throw new PlatformException('FBR_CREDIT_SOURCE_NOT_ACCEPTED', 'Only an accepted live FBR Invoice with lines can have a full credit note.', 409);
            }

            $previous = (int) $company->fbr_credit_note_sequence;
            if ($previous < 0 || $previous >= PHP_INT_MAX) {
                throw new PlatformException('FBR_CREDIT_SEQUENCE_EXHAUSTED', 'Credit-note numbering is exhausted.', 409);
            }
            $sequence = $previous + 1;
            $company->forceFill(['fbr_credit_note_sequence' => $sequence])->save();
            $credit = PakistanFbrCreditNote::query()->create([
                'company_id' => $companyId, 'invoice_id' => $document->id, 'sequence' => $sequence,
                'credit_note_number' => 'PKFC-'.str_pad((string) $sequence, 8, '0', STR_PAD_LEFT),
                'idempotency_key' => $key, 'payload_hash' => $hash, 'state' => 'DRAFT',
                'issued_on' => now()->timezone($company->timezone)->toDateString(), 'reason' => $data['reason'],
                'original_invoice_number' => $document->invoice_number,
                'original_fbr_reference_number' => $document->fbr_reference_number,
                'original_fbr_evidence' => $document->fbr_response_metadata,
                'seller_snapshot' => $document->seller_snapshot,
                'buyer_snapshot' => $document->buyer_snapshot,
                'portal_snapshot' => $document->print_snapshot['portal'] ?? null,
                'taxable_amount' => $document->taxable_amount,
                'sales_tax' => $document->sales_tax,
                'extra_tax' => $document->other_tax,
                'further_tax' => $document->advance_tax,
                'st_withheld' => $document->withholding_tax,
                'total' => $document->total,
                'created_by' => $user->id,
            ]);
            $credit->lines()->createMany($document->lines->map(fn ($line): array => [
                'invoice_line_id' => $line->id,
                'source_snapshot' => $line->only([
                    'position', 'description', 'hs_code', 'quantity_milli', 'unit', 'unit_price', 'subtotal', 'discount',
                    'taxable_amount', 'tax_rate_bps', 'fbr_rate_id', 'tax_amount', 'other_tax_rate_bps', 'other_tax_amount',
                    'advance_tax_rate_bps', 'advance_tax_amount', 'withholding_tax_rate_bps', 'withholding_tax_amount',
                    'sro_schedule_id', 'sro_item_id', 'total', 'sales_type',
                ]),
                'taxable_amount' => $line->taxable_amount,
                'sales_tax' => $line->tax_amount,
                'extra_tax' => $line->other_tax_amount ?? 0,
                'further_tax' => $line->advance_tax_amount ?? 0,
                'st_withheld' => $line->withholding_tax_amount ?? 0,
                'total' => $line->total,
            ])->all());
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_credit_drafted', 'pakistan_fbr', $credit, null,
                ['invoice_id' => $document->id, 'credit_note_number' => $credit->credit_note_number, 'total' => $credit->total]);

            return $credit->load('lines');
        });
    }

    public function updateReason(string $companyId, User $user, string $invoiceId, string $creditId, string $reason): PakistanFbrCreditNote
    {
        return DB::transaction(function () use ($companyId, $user, $invoiceId, $creditId, $reason): PakistanFbrCreditNote {
            $credit = PakistanFbrCreditNote::query()->where('company_id', $companyId)->where('invoice_id', $invoiceId)
                ->lockForUpdate()->findOrFail($creditId);
            if (! in_array($credit->state, ['DRAFT', 'REJECTED'], true)) {
                throw new PlatformException('FBR_CREDIT_LOCKED', 'This Credit Note cannot be edited after submission.', 409);
            }
            $oldReason = $credit->reason;
            $credit->update(['reason' => $reason]);
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_credit_reason_updated', 'pakistan_fbr', $credit,
                ['reason' => $oldReason], ['reason' => $reason]);

            return $credit->load('lines');
        });
    }

    public function deleteDraft(string $companyId, User $user, string $invoiceId, string $creditId): void
    {
        DB::transaction(function () use ($companyId, $user, $invoiceId, $creditId): void {
            $credit = PakistanFbrCreditNote::query()->where('company_id', $companyId)->where('invoice_id', $invoiceId)
                ->lockForUpdate()->findOrFail($creditId);
            if ($credit->state !== 'DRAFT' || $credit->attempts()->exists()) {
                throw new PlatformException('FBR_CREDIT_LOCKED', 'Only an unsubmitted Credit Note draft may be deleted.', 409);
            }
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_credit_draft_deleted', 'pakistan_fbr', $credit,
                null, ['credit_note_number' => $credit->credit_note_number, 'invoice_id' => $invoiceId]);
            $credit->lines()->each(fn ($line) => $line->delete());
            $credit->delete();
        });
    }
}
