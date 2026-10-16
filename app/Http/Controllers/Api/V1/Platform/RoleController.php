<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditService;
use App\Services\Platform\PlatformAccessService;
use App\Services\Platform\PlatformAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.roles.view');
        $roles = Role::query()->with('permissions:id,name,description')->withCount('permissions')
            ->where('company_id', $companyId)->orderBy('name')->get();
        $roles->each(fn (Role $role) => $role->setAttribute('memberships_count', CompanyUser::query()
            ->where('company_id', $companyId)->where('is_active', true)
            ->where(fn ($query) => $query->where('role_id', $role->id)
                ->orWhereHas('roles', fn ($assigned) => $assigned->whereKey($role->id)))
            ->count()));

        return response()->json(['roles' => $roles, 'permissions' => Permission::query()->whereNotIn('name', PlatformAuthority::PERMISSIONS)->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.roles.manage');
        $data = $this->validated($request, $companyId);
        $this->preventEscalation($request, $companyId, $data['permission_ids']);
        $role = DB::transaction(function () use ($data, $companyId): Role {
            $role = Role::query()->create(['company_id' => $companyId, 'name' => $data['name'], 'is_system' => false]);
            $role->permissions()->sync($data['permission_ids']);

            return $role;
        });
        $this->audit->record($request, $request->user(), $companyId, 'role_created', 'platform', $role, null, $role->load('permissions')->toArray());

        return response()->json($role->load('permissions'), 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->owned($request, $role);
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.roles.manage');
        abort_if($role->is_system, 409, 'System roles cannot be modified.');
        $data = $this->validated($request, $companyId, $role->id);
        $this->preventEscalation($request, $companyId, $data['permission_ids']);
        $old = $role->load('permissions')->toArray();
        DB::transaction(function () use ($role, $data): void {
            $role->update(['name' => $data['name']]);
            $role->permissions()->sync($data['permission_ids']);
        });
        $this->audit->record($request, $request->user(), $companyId, 'role_updated', 'platform', $role, $old, $role->fresh('permissions')->toArray());

        return response()->json($role->fresh('permissions'));
    }

    public function clone(Request $request, Role $role): JsonResponse
    {
        $this->owned($request, $role);
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.roles.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120', 'unique:roles,name,NULL,id,company_id,'.$companyId]]);
        $this->preventEscalation($request, $companyId, $role->permissions()->pluck('permissions.id')->all());
        $clone = Role::query()->create(['company_id' => $companyId, 'name' => $data['name'], 'is_system' => false]);
        $clone->permissions()->sync($role->permissions()->pluck('permissions.id'));
        $this->audit->record($request, $request->user(), $companyId, 'role_cloned', 'platform', $clone, null, $clone->load('permissions')->toArray());

        return response()->json($clone->load('permissions'), 201);
    }

    public function archive(Request $request, Role $role): JsonResponse
    {
        $this->owned($request, $role);
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.roles.manage');
        $role = DB::transaction(function () use ($request, $role, $companyId): Role {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($request->user(), $companyId, 'platform.roles.manage');
            $role = Role::query()->where('company_id', $companyId)->whereKey($role->id)->lockForUpdate()->firstOrFail();
            abort_if($role->is_system, 409, 'System roles cannot be archived.');
            abort_if(CompanyUser::query()->where('company_id', $companyId)->where(function ($query) use ($role): void {
                $query->where('role_id', $role->id)->orWhereHas('roles', fn ($roles) => $roles->whereKey($role->id));
            })->exists(), 409, 'Remove this role from every user before archiving it.');
            $old = $role->toArray();
            $role->update(['archived_at' => now()]);
            $this->audit->record($request, $request->user(), $companyId, 'role_archived', 'platform', $role, $old, $role->fresh()->toArray());

            return $role;
        });

        return response()->json($role);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->owned($request, $role);
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.roles.manage');

        DB::transaction(function () use ($request, $role, $companyId): void {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($request->user(), $companyId, 'platform.roles.manage');
            $role = Role::query()->where('company_id', $companyId)->whereKey($role->id)->lockForUpdate()->firstOrFail();
            if ($role->is_system || $role->archived_at === null) {
                throw new PlatformException('ROLE_DELETE_BLOCKED', 'Only archived custom roles may be deleted.', 409);
            }
            if (CompanyUser::query()->where('company_id', $companyId)
                ->where(fn ($query) => $query->where('role_id', $role->id)
                    ->orWhereHas('roles', fn ($assigned) => $assigned->whereKey($role->id)))->exists()
                || CompanyInvitation::query()->where('company_id', $companyId)->whereJsonContains('role_ids', $role->id)->exists()
                || $this->hasAssignmentAudit($companyId, $role->id)) {
                throw new PlatformException('ROLE_DELETE_BLOCKED', 'This role has assignment or invitation history and cannot be deleted.', 409);
            }

            $snapshot = $role->load('permissions')->toArray();
            $this->audit->record($request, $request->user(), $companyId, 'role_deleted', 'platform', $role, $snapshot, null);
            $role->delete();
        });

        return response()->json(['message' => 'Archived custom role deleted.']);
    }

    private function hasAssignmentAudit(string $companyId, int $roleId): bool
    {
        return AuditLog::query()->where('company_id', $companyId)
            ->where('entity_type', '!=', Role::class)
            ->cursor()->contains(fn (AuditLog $log): bool => $this->mentionsRole($log->old_values, $roleId)
                || $this->mentionsRole($log->new_values, $roleId));
    }

    /** @param array<string|int, mixed>|null $values */
    private function mentionsRole(?array $values, int $roleId): bool
    {
        if ($values === null) {
            return false;
        }
        foreach ($values as $key => $value) {
            if ($key === 'role_id' && (int) $value === $roleId) {
                return true;
            }
            if ($key === 'role_ids' && is_array($value) && in_array($roleId, $value, true)) {
                return true;
            }
            if ($key === 'roles' && is_array($value) && collect($value)->contains(fn ($assigned): bool => is_array($assigned) && (int) ($assigned['id'] ?? 0) === $roleId)) {
                return true;
            }
            if (is_array($value) && $this->mentionsRole($value, $roleId)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{name:string,permission_ids:array<int,int>} */
    private function validated(Request $request, string $companyId, ?int $ignoreId = null): array
    {
        $unique = 'unique:roles,name'.($ignoreId === null ? ',NULL,id' : ','.$ignoreId).',company_id,'.$companyId;

        return $request->validate(['name' => ['required', 'string', 'max:120', $unique], 'permission_ids' => ['required', 'array'], 'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id']]);
    }

    /** @param array<int, int> $permissionIds */
    private function preventEscalation(Request $request, string $companyId, array $permissionIds): void
    {
        if (Permission::query()->whereKey($permissionIds)->whereIn('name', PlatformAuthority::PERMISSIONS)->exists()) {
            throw new PlatformException('PRIVILEGE_ESCALATION_DENIED', 'Global platform permissions cannot be assigned to company roles.', 403);
        }
        $actor = CompanyUser::query()->with(['role.permissions', 'roles.permissions'])->where('company_id', $companyId)->where('user_id', $request->user()->id)->firstOrFail();
        $owned = collect([$actor->role])->merge($actor->roles)->filter()->flatMap(fn ($role) => $role->permissions)->pluck('id')->unique();
        if (! $request->user()->hasCompanyPermission($companyId, '*') && collect($permissionIds)->diff($owned)->isNotEmpty()) {
            throw new PlatformException('PRIVILEGE_ESCALATION_DENIED', 'You cannot grant permissions you do not hold.', 403);
        }
    }

    private function owned(Request $request, Role $role): void
    {
        abort_unless($role->company_id === $this->companyId($request), 404);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
