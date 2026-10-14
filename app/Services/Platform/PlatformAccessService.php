<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformException;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

class PlatformAccessService
{
    public function __construct(private readonly PlatformAuthority $platformAuthority) {}

    public function authorize(User $user, string $companyId, string $permission): void
    {
        $allowed = $this->platformAuthority->isGlobal($permission)
            ? $this->platformAuthority->allows($user, $permission)
            : $user->hasCompanyPermission($companyId, $permission);
        if (! $allowed) {
            throw new PlatformException('PERMISSION_DENIED', 'You do not have permission to perform this action.', 403);
        }
    }

    /** @return array<int, string> */
    public function permissionNames(User $user, string $companyId): array
    {
        $membership = CompanyUser::query()->with(['role.permissions:id,name', 'roles.permissions:id,name'])
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        return collect([$membership?->role])
            ->merge($membership?->roles ?? [])
            ->filter()
            ->flatMap(fn ($role) => $role->permissions)
            ->pluck('name')
            ->reject(fn (string $name): bool => $this->platformAuthority->isGlobal($name))
            ->unique()
            ->values()
            ->all();
    }

    /** @return Collection<int, Role> */
    public function assignableRoles(User $user, string $companyId): Collection
    {
        $roles = Role::query()->with('permissions:id')->where('company_id', $companyId)
            ->whereNull('archived_at')->orderBy('name')->orderBy('id')->get(['id', 'company_id', 'name']);
        if ($user->hasCompanyPermission($companyId, '*')) {
            return $roles;
        }

        $ownedPermissionIds = $this->ownedPermissionIds($user, $companyId);

        return $roles->filter(fn (Role $role): bool => $role->permissions->pluck('id')->diff($ownedPermissionIds)->isEmpty())->values();
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

        $grantedPermissionIds = $roles->flatMap(fn (Role $role) => $role->permissions)->pluck('id')->unique();

        if ($grantedPermissionIds->diff($this->ownedPermissionIds($user, $companyId))->isNotEmpty()) {
            throw new PlatformException('PRIVILEGE_ESCALATION_DENIED', 'You cannot assign permissions you do not hold.', 403);
        }
    }

    /** @return array<int, int> */
    private function ownedPermissionIds(User $user, string $companyId): array
    {
        $actor = CompanyUser::query()->with(['role.permissions:id', 'roles.permissions:id'])
            ->where('company_id', $companyId)->where('user_id', $user->id)->where('is_active', true)->firstOrFail();

        return collect([$actor->role])->merge($actor->roles)->filter()
            ->flatMap(fn ($role) => $role->permissions)->pluck('id')->unique()->all();
    }
}
