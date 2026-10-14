<?php

namespace App\Services\Platform;

use App\Models\Permission;
use App\Models\User;

class PlatformAuthority
{
    public const PERMISSIONS = [
        'platform.plans.manage',
        'platform.jobs.view', 'platform.jobs.manage',
        'platform.companies.view', 'platform.companies.approve', 'platform.companies.activate',
        'fbr.hs.manage',
    ];

    public function allows(User $user, string $permission): bool
    {
        return $user->is_platform_admin && in_array($permission, self::PERMISSIONS, true)
            && $user->platformPermissions()->where('name', $permission)->exists();
    }

    public function isGlobal(string $permission): bool
    {
        return in_array($permission, self::PERMISSIONS, true);
    }

    /** @return array<int, string> */
    public function permissions(User $user): array
    {
        return $user->is_platform_admin ? $user->platformPermissions()->whereIn('name', self::PERMISSIONS)->pluck('name')->all() : [];
    }

    /** @param array<int, int> $permissionIds */
    public function grant(User $user, array $permissionIds): void
    {
        if ($user->is_platform_admin) {
            $user->platformPermissions()->syncWithoutDetaching($permissionIds);
        }
    }

    public function grantAll(User $user): void
    {
        $this->grant($user, Permission::query()->whereIn('name', self::PERMISSIONS)->pluck('id')->all());
    }
}
