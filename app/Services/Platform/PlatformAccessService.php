<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformException;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;

class PlatformAccessService
{
    public function authorize(User $user, string $companyId, string $permission): void
    {
        if (! $user->hasCompanyPermission($companyId, $permission)) {
            throw new PlatformException('PERMISSION_DENIED', 'You do not have permission to perform this action.', 403);
        }
    }

    /** @param array<int, int> $roleIds */
    public function assertCanAssignRoles(User $user, string $companyId, array $roleIds): void
    {
        $roles = Role::query()->with('permissions:id')->where('company_id', $companyId)->whereNull('archived_at')->whereKey($roleIds)->get();
        if ($roles->count() !== count(array_unique($roleIds))) {
            throw new PlatformException('TENANT_ACCESS_DENIED', 'One or more selected roles do not belong to this company.', 403);
        }
        if ($user->hasCompanyPermission($companyId, '*')) {
            return;
        }

        $actor = CompanyUser::query()->with(['role.permissions:id', 'roles.permissions:id'])
            ->where('company_id', $companyId)->where('user_id', $user->id)->where('is_active', true)->firstOrFail();
        $ownedPermissionIds = collect([$actor->role])->merge($actor->roles)->filter()
            ->flatMap(fn ($role) => $role->permissions)->pluck('id')->unique();
        $grantedPermissionIds = $roles->flatMap(fn (Role $role) => $role->permissions)->pluck('id')->unique();

        if ($grantedPermissionIds->diff($ownedPermissionIds)->isNotEmpty()) {
            throw new PlatformException('PRIVILEGE_ESCALATION_DENIED', 'You cannot assign permissions you do not hold.', 403);
        }
    }
}
