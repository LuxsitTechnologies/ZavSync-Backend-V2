<?php

namespace App\Services\Crm;

use App\Exceptions\CrmException;
use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmLeadConversionService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function convert(Request $request, CrmLead $lead, array $data): CrmLead
    {
        return DB::transaction(function () use ($request, $lead, $data): CrmLead {
            $companyId = (string) $request->attributes->get('company_id');
            $locked = CrmLead::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($lead->id);
            $keyOwner = CrmLead::query()->where('company_id', $companyId)->where('conversion_idempotency_key', $data['idempotency_key'])->whereKeyNot($locked->id)->exists();
            if ($keyOwner) {
                throw new CrmException('CRM_IDEMPOTENCY_KEY_REUSED', 'This idempotency key was already used for another lead.', 409);
            }
            if ($locked->status === 'CONVERTED') {
                return $locked->load(['convertedAccount', 'convertedContact', 'convertedDeal']);
            }
            if ($locked->status !== 'QUALIFIED') {
                throw new CrmException('CRM_LEAD_NOT_QUALIFIED', 'Only a qualified lead can be converted.', 409);
            }

            $account = $this->account($locked, $data, $companyId, (int) $request->user()->id);
            $contact = $this->contact($locked, $account, $data, $companyId, (int) $request->user()->id);
            $deal = $this->deal($locked, $account, $contact, $data, $companyId, (int) $request->user()->id);
            $old = $locked->toArray();
            $locked->update(['status' => 'CONVERTED', 'converted_at' => now(), 'conversion_idempotency_key' => $data['idempotency_key'], 'converted_account_id' => $account?->id, 'converted_contact_id' => $contact?->id, 'converted_deal_id' => $deal?->id, 'updated_by' => $request->user()->id]);
            $this->audit->record($request, $request->user(), $companyId, 'convert', 'crm_lead', $locked, $old, $locked->fresh()->toArray());

            return $locked->fresh()->load(['convertedAccount', 'convertedContact', 'convertedDeal']);
        });
    }

    /** @param array<string, mixed> $data */
    private function account(CrmLead $lead, array $data, string $companyId, int $userId): ?CrmAccount
    {
        if (! empty($data['account_id'])) {
            return CrmAccount::query()->where('company_id', $companyId)->findOrFail($data['account_id']);
        }
        if (! $data['create_account']) {
            return $lead->account()->first();
        }

        return CrmAccount::query()->create(['company_id' => $companyId, 'owner_id' => $lead->owner_id, 'name' => $lead->company_name ?: trim($lead->first_name.' '.$lead->last_name), 'email' => $lead->email, 'phone' => $lead->phone, 'website' => $lead->website, 'source' => $lead->source, 'status' => 'PROSPECT', 'account_type' => 'BUSINESS', 'country' => 'PK', 'created_by' => $userId]);
    }

    /** @param array<string, mixed> $data */
    private function contact(CrmLead $lead, ?CrmAccount $account, array $data, string $companyId, int $userId): ?CrmContact
    {
        if (! empty($data['contact_id'])) {
            $contact = CrmContact::query()->where('company_id', $companyId)->findOrFail($data['contact_id']);
            if ($account && $contact->account_id && $contact->account_id !== $account->id) {
                throw new CrmException('CRM_CONTACT_ACCOUNT_MISMATCH', 'The selected contact belongs to another CRM account.');
            }

            return $contact;
        }
        if (! $data['create_contact']) {
            return $lead->contact()->first();
        }

        return CrmContact::query()->create(['company_id' => $companyId, 'account_id' => $account?->id, 'owner_id' => $lead->owner_id, 'first_name' => $lead->first_name, 'last_name' => $lead->last_name, 'job_title' => $lead->job_title, 'email' => $lead->email, 'phone' => $lead->phone, 'mobile' => $lead->mobile, 'is_primary' => $account !== null && ! $account->contacts()->exists(), 'status' => 'ACTIVE', 'created_by' => $userId]);
    }

    /** @param array<string, mixed> $data */
    private function deal(CrmLead $lead, ?CrmAccount $account, ?CrmContact $contact, array $data, string $companyId, int $userId): ?CrmDeal
    {
        if (! $data['create_deal']) {
            return null;
        }
        if (! $account) {
            throw new CrmException('CRM_DEAL_ACCOUNT_REQUIRED', 'A CRM account is required to create the deal.');
        }
        $pipeline = ! empty($data['pipeline_id'])
            ? CrmPipeline::query()->where('company_id', $companyId)->findOrFail($data['pipeline_id'])
            : CrmPipeline::query()->where('company_id', $companyId)->where('is_default', true)->where('is_active', true)->first();
        if (! $pipeline) {
            throw new CrmException('CRM_PIPELINE_REQUIRED', 'Create or select an active CRM pipeline before converting this lead.');
        }
        $stage = ! empty($data['pipeline_stage_id'])
            ? CrmPipelineStage::query()->where('company_id', $companyId)->where('pipeline_id', $pipeline->id)->findOrFail($data['pipeline_stage_id'])
            : $pipeline->stages()->where('is_active', true)->orderBy('position')->first();
        if (! $stage) {
            throw new CrmException('CRM_PIPELINE_STAGE_REQUIRED', 'The selected pipeline has no active stage.');
        }

        return CrmDeal::query()->create(['company_id' => $companyId, 'account_id' => $account->id, 'primary_contact_id' => $contact?->id, 'lead_origin_id' => $lead->id, 'pipeline_id' => $pipeline->id, 'pipeline_stage_id' => $stage->id, 'owner_id' => $lead->owner_id, 'title' => $data['deal_title'] ?? (($lead->company_name ?: $lead->first_name).' opportunity'), 'amount' => $lead->estimated_value, 'currency' => $lead->currency, 'probability_bps' => $stage->probability_bps, 'expected_close_date' => $lead->expected_timeframe, 'status' => $stage->is_won ? 'WON' : ($stage->is_lost ? 'LOST' : 'OPEN'), 'source' => $lead->source, 'description' => $lead->notes, 'closed_at' => ($stage->is_won || $stage->is_lost) ? now() : null, 'actual_close_date' => ($stage->is_won || $stage->is_lost) ? now()->toDateString() : null, 'created_by' => $userId]);
    }
}
