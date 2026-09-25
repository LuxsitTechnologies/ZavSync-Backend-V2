<?php

namespace App\Services\Outreach;

use App\Models\OutreachMessage;

class OutreachReportService
{
    /** @return array<string, mixed> */
    public function summary(string $companyId): array
    {
        $query = OutreachMessage::query()->where('company_id', $companyId);
        $sent = (clone $query)->whereNotNull('sent_at')->count();
        $metric = fn (string $type): int => (clone $query)->whereHas('events', fn ($events) => $events->where('type', $type))->count();
        $delivered = (clone $query)->whereNotNull('delivered_at')->count();
        $failed = (clone $query)->where('state', 'FAILED')->count();
        $bounced = (clone $query)->where('state', 'BOUNCED')->count();
        $replies = $metric('REPLIED');
        $unsubscribes = $metric('UNSUBSCRIBED');
        $complaints = $metric('COMPLAINT');
        $opens = $metric('OPENED');
        $clicks = $metric('CLICKED');
        $rate = fn (int $count): int => $sent === 0 ? 0 : intdiv($count * 10_000, $sent);

        return compact('sent', 'delivered', 'failed', 'bounced', 'replies', 'unsubscribes', 'complaints', 'opens', 'clicks') + ['delivery_rate_bps' => $rate($delivered), 'reply_rate_bps' => $rate($replies), 'open_rate_bps' => $rate($opens), 'click_rate_bps' => $rate($clicks), 'by_sequence' => $this->breakdown($companyId, 'sequence_id'), 'by_template' => $this->templateBreakdown($companyId), 'by_sender' => $this->breakdown($companyId, 'sending_identity_id')];
    }

    /** @return array<int, array<string, mixed>> */
    private function breakdown(string $companyId, string $column): array
    {
        return OutreachMessage::query()->where('company_id', $companyId)->selectRaw("{$column} as id, count(*) as total, count(sent_at) as sent, count(delivered_at) as delivered, count(replied_at) as replied")->groupBy($column)->orderBy($column)->get()->toArray();
    }

    /** @return array<int, array<string, mixed>> */
    private function templateBreakdown(string $companyId): array
    {
        return OutreachMessage::query()->where('outreach_messages.company_id', $companyId)->join('outreach_sequence_steps', 'outreach_sequence_steps.id', '=', 'outreach_messages.sequence_step_id')->selectRaw('outreach_sequence_steps.template_id as id, count(*) as total, count(outreach_messages.sent_at) as sent, count(outreach_messages.delivered_at) as delivered')->groupBy('outreach_sequence_steps.template_id')->orderBy('outreach_sequence_steps.template_id')->get()->toArray();
    }
}
