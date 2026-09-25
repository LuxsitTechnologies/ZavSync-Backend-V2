<?php

namespace App\Services\Outreach;

use App\Exceptions\OutreachException;
use App\Models\EmailProviderConnection;
use App\Models\EmailSendingIdentity;
use App\Services\Platform\EntitlementService;
use Illuminate\Support\Facades\DB;

class SendingIdentityService
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @param array<string, mixed> $data */
    public function create(string $companyId, int $userId, array $data): EmailSendingIdentity
    {
        $this->entitlements->assertWithinLimit($companyId, 'sending_identities', EmailSendingIdentity::query()->where('company_id', $companyId)->count());

        return DB::transaction(function () use ($companyId, $userId, $data): EmailSendingIdentity {
            $connection = EmailProviderConnection::query()->where('company_id', $companyId)->where('status', 'CONNECTED')->lockForUpdate()->findOrFail($data['provider_connection_id']);
            EmailSendingIdentity::query()->where('company_id', $companyId)->lockForUpdate()->get();
            $hasDefault = EmailSendingIdentity::query()->where('company_id', $companyId)->where('is_default', true)->exists();
            $makeDefault = ! $hasDefault || (bool) ($data['is_default'] ?? false);
            if ($makeDefault) {
                EmailSendingIdentity::query()->where('company_id', $companyId)->update(['is_default' => false]);
            }

            return EmailSendingIdentity::query()->create([...$data, 'company_id' => $companyId, 'verification_status' => $connection->provider_type === 'SMTP' ? 'VERIFIED' : 'PENDING', 'verified_at' => $connection->provider_type === 'SMTP' ? now() : null, 'is_default' => $makeDefault, 'created_by' => $userId]);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(EmailSendingIdentity $identity, int $userId, array $data): EmailSendingIdentity
    {
        return DB::transaction(function () use ($identity, $userId, $data): EmailSendingIdentity {
            $locked = EmailSendingIdentity::query()->where('company_id', $identity->company_id)->lockForUpdate()->findOrFail($identity->id);
            if (isset($data['provider_connection_id']) && $data['provider_connection_id'] !== $locked->provider_connection_id) {
                $connection = EmailProviderConnection::query()->where('company_id', $identity->company_id)->where('status', 'CONNECTED')->findOrFail($data['provider_connection_id']);
                $data['verification_status'] = $connection->provider_type === 'SMTP' ? 'VERIFIED' : 'PENDING';
                $data['verified_at'] = $connection->provider_type === 'SMTP' ? now() : null;
            }
            if (($data['is_default'] ?? false) === true) {
                EmailSendingIdentity::query()->where('company_id', $identity->company_id)->whereKeyNot($identity->id)->update(['is_default' => false]);
            }
            if ($locked->is_default && array_key_exists('is_default', $data) && $data['is_default'] === false) {
                throw new OutreachException('DEFAULT_IDENTITY_REQUIRED', 'Assign another default identity before removing this default.', 409);
            }
            if (($data['is_active'] ?? $locked->is_active) === false && $locked->is_default) {
                throw new OutreachException('DEFAULT_IDENTITY_REQUIRED', 'Assign another default identity before deactivating this identity.', 409);
            }
            $locked->update([...$data, 'updated_by' => $userId]);

            return $locked->fresh();
        });
    }
}
