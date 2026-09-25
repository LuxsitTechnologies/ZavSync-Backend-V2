<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Services\AuditService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyUserController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly EntitlementService $entitlements, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.users.view');
        $query = CompanyUser::query()->with(['user:id,name,email', 'role.permissions:id,name', 'roles.permissions:id,name'])->where('company_id', $companyId);
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->whereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        if ($request->filled('status')) {
            $query->where('is_active', $request->string('status')->toString() === 'active');
        }

        return response()->json($query->orderByDesc('created_at')->paginate(min($request->integer('per_page', 25), 100)));
    }

    public function show(Request $request, CompanyUser $membership): JsonResponse
    {
        $this->owned($request, $membership);
        $this->access->authorize($request->user(), $this->companyId($request), 'platform.users.view');
        $membership->load(['user:id,name,email', 'role.permissions:id,name', 'roles.permissions:id,name']);
        $effective = collect([$membership->role])->merge($membership->roles)->filter()->flatMap(fn ($role) => $role->permissions)->pluck('name')->unique()->sort()->values();

        return response()->json(['membership' => $membership, 'effective_permissions' => $effective]);
    }

    public function updateRoles(Request $request, CompanyUser $membership): JsonResponse
    {
        $this->owned($request, $membership);
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.users.manage');
        $data = $request->validate(['role_ids' => ['present', 'array'], 'role_ids.*' => ['integer', 'distinct']]);
        $roles = Role::query()->where('company_id', $companyId)->whereNull('archived_at')->whereKey($data['role_ids'])->get();
        abort_unless($roles->count() === count($data['role_ids']), 404);
        abort_if($membership->user_id === $request->user()->id && $roles->isEmpty(), 409, 'You cannot remove every role from your own membership.');
        $this->access->assertCanAssignRoles($request->user(), $companyId, $data['role_ids']);
        $old = $membership->load('roles')->toArray();
        $membership->roles()->sync($roles->modelKeys());
        $membership->update(['role_id' => $roles->first()?->id]);
        $this->audit->record($request, $request->user(), $companyId, 'roles_updated', 'platform', $membership, $old, $membership->fresh('roles')->toArray());

        return response()->json($membership->fresh(['user', 'roles.permissions']));
    }

    public function updateStatus(Request $request, CompanyUser $membership): JsonResponse
    {
        $this->owned($request, $membership);
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.users.manage');
        $data = $request->validate(['active' => ['required', 'boolean']]);
        abort_if($membership->user_id === $request->user()->id && ! $data['active'], 409, 'You cannot suspend your own membership.');
        $old = $membership->toArray();
        $membership->update([
            'is_active' => $data['active'],
            'suspended_at' => $data['active'] ? null : now(),
            'suspended_by' => $data['active'] ? null : $request->user()->id,
        ]);
        $this->audit->record($request, $request->user(), $companyId, $data['active'] ? 'reactivated' : 'suspended', 'platform', $membership, $old, $membership->fresh()->toArray());

        return response()->json($membership->fresh('user'));
    }

    public function destroy(Request $request, CompanyUser $membership): JsonResponse
    {
        $this->owned($request, $membership);
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.users.manage');
        abort_if($membership->user_id === $request->user()->id, 409, 'You cannot remove your own membership.');
        $old = $membership->toArray();
        $this->audit->record($request, $request->user(), $companyId, 'membership_removed', 'platform', $membership, $old, null);
        $membership->delete();

        return response()->json(['message' => 'Company membership removed.']);
    }

    private function owned(Request $request, CompanyUser $membership): void
    {
        abort_unless($membership->company_id === $this->companyId($request), 404);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
