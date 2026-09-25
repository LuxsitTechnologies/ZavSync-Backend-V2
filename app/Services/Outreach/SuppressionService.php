<?php

namespace App\Services\Outreach;

use App\Models\OutreachSuppression;
use Illuminate\Support\Str;

class SuppressionService
{
    public function isSuppressed(string $companyId, string $email): bool
    {
        return OutreachSuppression::query()->where('company_id', $companyId)->where('normalized_email', $this->normalize($email))->where('is_active', true)->exists();
    }

    public function suppress(string $companyId, string $email, string $reason, string $source, ?int $userId = null, ?string $details = null): OutreachSuppression
    {
        return OutreachSuppression::query()->updateOrCreate(
            ['company_id' => $companyId, 'normalized_email' => $this->normalize($email)],
            ['email' => trim($email), 'reason' => $reason, 'source' => $source, 'details' => $details, 'is_active' => true, 'suppressed_at' => now(), 'removed_at' => null, 'created_by' => $userId, 'removed_by' => null],
        );
    }

    public function remove(OutreachSuppression $suppression, int $userId): OutreachSuppression
    {
        $suppression->update(['is_active' => false, 'removed_at' => now(), 'removed_by' => $userId]);

        return $suppression->fresh();
    }

    private function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }
}
