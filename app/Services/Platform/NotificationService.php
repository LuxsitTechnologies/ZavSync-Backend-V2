<?php

namespace App\Services\Platform;

use App\Jobs\DeliverPlatformNotification;
use App\Models\NotificationPreference;
use App\Models\PlatformNotification;

class NotificationService
{
    /** @var array<int, string> */
    public const CRITICAL_TYPES = ['security.login', 'security.password_changed', 'security.membership_suspended'];

    /** @param array<string, mixed> $metadata */
    public function create(string $companyId, int $recipientId, string $type, string $title, string $message, array $metadata = [], ?string $relatedUrl = null): PlatformNotification
    {
        $preference = NotificationPreference::query()->where('company_id', $companyId)->where('user_id', $recipientId)->where('type', $type)->first();
        $inAppEnabled = in_array($type, self::CRITICAL_TYPES, true) || $preference?->in_app_enabled !== false;
        if (! $inAppEnabled) {
            return new PlatformNotification(['company_id' => $companyId, 'recipient_id' => $recipientId, 'type' => $type, 'delivery_state' => 'SKIPPED']);
        }

        $notification = PlatformNotification::query()->create([
            'company_id' => $companyId, 'recipient_id' => $recipientId, 'type' => $type, 'channel' => 'IN_APP',
            'title' => $title, 'message' => $message, 'metadata' => $metadata, 'related_url' => $relatedUrl,
        ]);
        DeliverPlatformNotification::dispatch($notification->id)->afterCommit();
        $emailEnabled = in_array($type, self::CRITICAL_TYPES, true) || $preference?->email_enabled !== false;
        if ($emailEnabled) {
            $email = PlatformNotification::query()->create([
                'company_id' => $companyId, 'recipient_id' => $recipientId, 'type' => $type, 'channel' => 'EMAIL',
                'title' => $title, 'message' => $message, 'metadata' => $metadata, 'related_url' => $relatedUrl,
            ]);
            DeliverPlatformNotification::dispatch($email->id)->afterCommit();
        }

        return $notification;
    }
}
