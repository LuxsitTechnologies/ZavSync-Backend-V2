<?php

namespace App\Services\Outreach;

use App\Exceptions\OutreachException;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\OutreachEnrollment;
use App\Models\OutreachMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MessagePreparationService
{
    public function __construct(private readonly TemplateRenderer $renderer, private readonly OutreachSchedulingService $scheduling) {}

    public function prepareNext(OutreachEnrollment $enrollment, ?Model $recipient = null): ?OutreachMessage
    {
        $enrollment->loadMissing('sequence.steps.template', 'sequence.sendingIdentity');
        $sequence = $enrollment->sequence;
        $position = $enrollment->current_step_position;
        $wait = 0;
        $step = $sequence->steps->firstWhere('position', $position);
        while ($step?->type === 'WAIT') {
            $wait += $step->wait_minutes;
            $position++;
            $step = $sequence->steps->firstWhere('position', $position);
        }
        if ($step === null) {
            $enrollment->update(['status' => 'COMPLETED', 'ended_at' => now(), 'next_action_at' => null, 'current_step_position' => $position]);

            return null;
        }
        $recipient ??= $this->recipient($enrollment);
        $settings = CompanySetting::query()->where('company_id', $enrollment->company_id)->first();
        if ($settings === null || blank($settings->outreach_physical_address)) {
            throw new OutreachException('COMPLIANCE_SETTINGS_REQUIRED', 'A company physical address is required before outreach can be scheduled.', 409);
        }
        $identity = $sequence->sendingIdentity;
        if ($identity === null) {
            throw new OutreachException('SENDING_IDENTITY_REQUIRED', 'A sending identity is required.', 409);
        }
        $source = $step->template;
        $subjectTemplate = $source?->subject ?? $step->subject ?? '';
        $textTemplate = $source?->body_text ?? $step->body_text ?? '';
        $htmlTemplate = $source?->body_html ?? $step->body_html;
        $variables = $this->context($enrollment, $recipient, $identity->from_name);
        $subject = $this->renderer->render($subjectTemplate, $variables);
        $bodyText = $this->renderer->render($textTemplate, $variables);
        $bodyHtml = $htmlTemplate === null ? null : $this->renderer->sanitizeHtml($this->renderer->render($htmlTemplate, $variables, true));
        $unsubscribeToken = Str::random(64);
        $trackingToken = Str::random(64);
        $unsubscribeUrl = url('/api/v1/outreach/unsubscribe/'.$unsubscribeToken);
        $footer = trim((string) $settings->outreach_footer."\n".$settings->outreach_physical_address."\nUnsubscribe: {$unsubscribeUrl}");
        $bodyHtml = ($bodyHtml ?: nl2br(e($bodyText))).'<hr><p>'.nl2br(e($footer)).'</p>';
        $bodyText .= "\n\n".$footer;
        $scheduledAt = $this->scheduling->nextEligible($sequence, CarbonImmutable::now('UTC'), $wait);
        $message = OutreachMessage::query()->create(['company_id' => $enrollment->company_id, 'enrollment_id' => $enrollment->id, 'sequence_id' => $sequence->id, 'sequence_step_id' => $step->id, 'sending_identity_id' => $identity->id, 'provider_connection_id' => $identity->provider_connection_id, 'template_id' => $step->template_id, 'stable_message_id' => Str::uuid().'@zavsync.local', 'recipient_type' => $enrollment->recipient_type, 'recipient_id' => $enrollment->recipient_id, 'to_email' => $enrollment->recipient_email, 'to_name' => $enrollment->recipient_name, 'from_email' => $identity->from_email, 'from_name' => $identity->from_name, 'reply_to_email' => $identity->reply_to_email, 'subject' => $subject, 'body_html' => $bodyHtml, 'body_text' => $bodyText, 'state' => 'SCHEDULED', 'scheduled_at' => $scheduledAt, 'unsubscribe_token_hash' => hash('sha256', $unsubscribeToken), 'tracking_token_hash' => hash('sha256', $trackingToken)]);
        if ($sequence->track_clicks && $settings->outreach_click_tracking_enabled) {
            $message->update(['body_html' => $this->trackLinks($message, (string) $message->body_html)]);
        }
        if ($sequence->track_opens && $settings->outreach_open_tracking_enabled) {
            $message->update(['body_html' => $message->body_html.'<img src="'.e(url('/api/v1/outreach/open/'.$message->id.'/'.$trackingToken)).'" width="1" height="1" alt="">']);
        }
        $enrollment->update(['current_step_position' => $position, 'next_action_at' => $scheduledAt]);

        return $message->fresh();
    }

    private function recipient(OutreachEnrollment $enrollment): Model
    {
        $model = $enrollment->recipient_type === 'CONTACT' ? CrmContact::class : CrmLead::class;

        return $model::query()->where('company_id', $enrollment->company_id)->findOrFail($enrollment->recipient_id);
    }

    /** @return array<string, string|null> */
    private function context(OutreachEnrollment $enrollment, Model $recipient, string $senderName): array
    {
        $account = $recipient->getAttribute('account_id') ? $recipient->account()->first() : null;
        $lead = $recipient instanceof CrmLead ? $recipient : CrmLead::query()->where('company_id', $enrollment->company_id)->where('contact_id', $recipient->getKey())->latest()->first();
        $deal = CrmDeal::query()->where('company_id', $enrollment->company_id)->when($recipient instanceof CrmContact, fn ($query) => $query->where('primary_contact_id', $recipient->getKey()))->when($recipient instanceof CrmLead, fn ($query) => $query->where('lead_origin_id', $recipient->getKey()))->latest()->first();

        return ['contact.first_name' => (string) $recipient->getAttribute('first_name'), 'contact.last_name' => (string) $recipient->getAttribute('last_name'), 'contact.email' => (string) $recipient->getAttribute('email'), 'account.name' => $account?->name, 'lead.name' => $lead ? trim($lead->first_name.' '.$lead->last_name) : null, 'deal.name' => $deal?->title, 'sender.name' => $senderName, 'company.name' => Company::query()->whereKey($enrollment->company_id)->value('name')];
    }

    private function trackLinks(OutreachMessage $message, string $html): string
    {
        return preg_replace_callback('/href=["\'](https?:\/\/[^"\']+)["\']/i', function (array $matches) use ($message): string {
            if (str_contains($matches[1], '/outreach/unsubscribe/')) {
                return 'href="'.e($matches[1]).'"';
            }
            $token = Str::random(64);
            $message->links()->create(['company_id' => $message->company_id, 'token_hash' => hash('sha256', $token), 'destination_url' => $matches[1]]);

            return 'href="'.e(url('/api/v1/outreach/click/'.$token)).'"';
        }, $html) ?? $html;
    }
}
