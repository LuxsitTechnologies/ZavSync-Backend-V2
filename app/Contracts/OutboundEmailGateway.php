<?php

namespace App\Contracts;

use App\Models\EmailProviderConnection;
use App\Models\OutreachMessage;
use App\Services\Outreach\ProviderSendResult;
use Illuminate\Http\Request;

interface OutboundEmailGateway
{
    /** @return array{success:bool,message:string} */
    public function verify(EmailProviderConnection $connection): array;

    public function send(EmailProviderConnection $connection, OutreachMessage $message): ProviderSendResult;

    public function webhookIsAuthentic(EmailProviderConnection $connection, Request $request): bool;

    /** @param array<string, mixed> $payload @return array<int, array{provider_event_id:string,provider_message_id:string,type:string,occurred_at:string,payload:array<string,mixed>}> */
    public function normalizeWebhook(EmailProviderConnection $connection, array $payload): array;

    /** @return array<int, array{provider_event_id:string,provider_message_id:string,occurred_at:string,payload:array<string,mixed>}> */
    public function synchronizeReplies(EmailProviderConnection $connection): array;
}
