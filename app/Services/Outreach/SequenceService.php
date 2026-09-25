<?php

namespace App\Services\Outreach;

use App\Exceptions\OutreachException;
use App\Models\CompanySetting;
use App\Models\EmailSendingIdentity;
use App\Models\OutreachSequence;
use App\Services\Platform\EntitlementService;
use Illuminate\Support\Facades\DB;

class SequenceService
{
    public function __construct(private readonly EntitlementService $entitlements, private readonly TemplateRenderer $renderer) {}

    /** @param array<string, mixed> $data */
    public function create(string $companyId, int $userId, array $data): OutreachSequence
    {
        $active = OutreachSequence::query()->where('company_id', $companyId)->where('status', 'ACTIVE')->count();
        $this->entitlements->assertWithinLimit($companyId, 'active_sequences', $active, 0);

        return DB::transaction(function () use ($companyId, $userId, $data): OutreachSequence {
            $steps = $data['steps'];
            unset($data['steps']);
            if (isset($data['sending_identity_id'])) {
                EmailSendingIdentity::query()->where('company_id', $companyId)->findOrFail($data['sending_identity_id']);
            }
            $sequence = OutreachSequence::query()->create([...$data, 'company_id' => $companyId, 'owner_id' => $data['owner_id'] ?? $userId, 'status' => 'DRAFT', 'created_by' => $userId]);
            $this->replaceSteps($sequence, $steps);

            return $sequence->load(['steps.template', 'sendingIdentity']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(OutreachSequence $sequence, int $userId, array $data): OutreachSequence
    {
        if ($sequence->status !== 'DRAFT') {
            throw new OutreachException('SEQUENCE_STRUCTURE_IMMUTABLE', 'Only draft sequences can be edited.', 409);
        }

        return DB::transaction(function () use ($sequence, $userId, $data): OutreachSequence {
            $steps = $data['steps'] ?? null;
            unset($data['steps'], $data['status']);
            $sequence->update([...$data, 'updated_by' => $userId]);
            if (is_array($steps)) {
                $sequence->steps()->delete();
                $this->replaceSteps($sequence, $steps);
            }

            return $sequence->fresh()->load(['steps.template', 'sendingIdentity']);
        });
    }

    public function transition(OutreachSequence $sequence, string $target, string $companyId, int $userId): OutreachSequence
    {
        return DB::transaction(function () use ($sequence, $target, $companyId, $userId): OutreachSequence {
            $locked = OutreachSequence::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($sequence->id);
            $allowed = ['DRAFT' => ['ACTIVE', 'ARCHIVED'], 'ACTIVE' => ['PAUSED', 'COMPLETED', 'ARCHIVED'], 'PAUSED' => ['ACTIVE', 'COMPLETED', 'ARCHIVED'], 'COMPLETED' => ['ARCHIVED'], 'ARCHIVED' => []];
            if (! in_array($target, $allowed[$locked->status] ?? [], true)) {
                throw new OutreachException('INVALID_SEQUENCE_TRANSITION', "Cannot transition {$locked->status} to {$target}.", 409);
            }
            if ($target === 'ACTIVE') {
                $this->assertActivatable($locked);
                if ($locked->status !== 'ACTIVE') {
                    $active = OutreachSequence::query()->where('company_id', $companyId)->where('status', 'ACTIVE')->count();
                    $this->entitlements->assertWithinLimit($companyId, 'active_sequences', $active);
                }
            }
            $timeField = match ($target) {
                'ACTIVE' => 'activated_at', 'PAUSED' => 'paused_at', 'COMPLETED' => 'completed_at', 'ARCHIVED' => 'archived_at', default => null
            };
            $attributes = ['status' => $target, 'updated_by' => $userId];
            if ($timeField !== null) {
                $attributes[$timeField] = now();
            }
            $locked->update($attributes);
            if ($target === 'PAUSED') {
                $locked->messages()->where('state', 'QUEUED')->update(['state' => 'SCHEDULED', 'queued_at' => null]);
            }
            if (in_array($target, ['COMPLETED', 'ARCHIVED'], true)) {
                $locked->messages()->whereIn('state', ['SCHEDULED', 'QUEUED'])->update(['state' => 'CANCELLED', 'cancelled_at' => now()]);
            }

            return $locked->fresh()->load(['steps', 'sendingIdentity']);
        });
    }

    /** @param array<int, array<string, mixed>> $steps */
    private function replaceSteps(OutreachSequence $sequence, array $steps): void
    {
        foreach (array_values($steps) as $index => $step) {
            if (($step['type'] ?? null) === 'EMAIL') {
                $this->renderer->variables((string) ($step['subject'] ?? ''), (string) ($step['body_text'] ?? ''), (string) ($step['body_html'] ?? ''));
            }
            $sequence->steps()->create([...$step, 'company_id' => $sequence->company_id, 'position' => $index + 1]);
        }
    }

    private function assertActivatable(OutreachSequence $sequence): void
    {
        $sequence->loadMissing(['steps.template', 'sendingIdentity.connection']);
        $identity = $sequence->sendingIdentity;
        if ($identity === null || ! $identity->is_active || $identity->verification_status !== 'VERIFIED' || $identity->connection?->status !== 'CONNECTED') {
            throw new OutreachException('VERIFIED_SENDER_REQUIRED', 'A verified active identity on a connected provider is required.', 409);
        }
        $hasInvalidEmail = $sequence->steps->where('type', 'EMAIL')->contains(fn ($step): bool => $step->template !== null ? ! $step->template->is_active : blank($step->subject) || blank($step->body_text));
        if ($sequence->steps->isEmpty() || $sequence->steps->first()->type !== 'EMAIL' || $hasInvalidEmail) {
            throw new OutreachException('INVALID_SEQUENCE_STEPS', 'The sequence must contain complete email steps and start with email.', 409);
        }
        if (blank(CompanySetting::query()->where('company_id', $sequence->company_id)->value('outreach_physical_address'))) {
            throw new OutreachException('COMPLIANCE_SETTINGS_REQUIRED', 'A company physical address is required before activation.', 409);
        }
    }
}
