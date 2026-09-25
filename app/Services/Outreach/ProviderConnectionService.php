<?php

namespace App\Services\Outreach;

use App\Contracts\OutboundEmailGateway;
use App\Models\EmailProviderConnection;

class ProviderConnectionService
{
    public function __construct(private readonly OutboundEmailGateway $gateway) {}

    public function verify(EmailProviderConnection $connection): EmailProviderConnection
    {
        $result = $this->gateway->verify($connection);
        $connection->update([
            'status' => $result['success'] ? 'CONNECTED' : 'ERROR',
            'last_verified_at' => $result['success'] ? now() : $connection->last_verified_at,
            'last_error' => $result['success'] ? null : $result['message'],
        ]);

        return $connection->fresh();
    }

    public function disconnect(EmailProviderConnection $connection): EmailProviderConnection
    {
        $connection->update(['status' => 'DISCONNECTED', 'access_token' => null, 'refresh_token' => null, 'token_expires_at' => null]);

        return $connection->fresh();
    }
}
