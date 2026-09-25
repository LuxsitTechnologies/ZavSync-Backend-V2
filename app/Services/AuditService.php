<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditService
{
    /** @param array<string, mixed>|null $oldValues @param array<string, mixed>|null $newValues */
    public function record(Request $request, User $user, string $companyId, string $action, string $module, Model $entity, ?array $oldValues = null, ?array $newValues = null): AuditLog
    {
        return AuditLog::query()->create([
            'company_id' => $companyId,
            'user_id' => $user->getKey(),
            'action' => $action,
            'module' => $module,
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => (string) $entity->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]);
    }
}
