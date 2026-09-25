<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Jobs\SendCompanyInvitation;
use App\Models\CompanyInvitation;
use App\Services\AuditService;
use App\Services\Platform\InvitationService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations, private readonly PlatformAccessService $access, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.users.view');

        return response()->json(CompanyInvitation::query()->with('inviter:id,name')->where('company_id', $companyId)->latest()->paginate(25));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.invitations.manage');
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255'], 'role_ids' => ['required', 'array', 'min:1'], 'role_ids.*' => ['integer', 'distinct']]);
        $this->access->assertCanAssignRoles($request->user(), $companyId, $data['role_ids']);
        $result = $this->invitations->invite($companyId, $data['email'], $data['role_ids'], $request->user());
        SendCompanyInvitation::dispatch($result['invitation']->id, Crypt::encryptString($result['token']))->afterCommit();
        $this->audit->record($request, $request->user(), $companyId, 'invitation_created', 'platform', $result['invitation'], null, $result['invitation']->toArray());

        return response()->json(['invitation' => $result['invitation'], 'acceptance_token' => app()->isProduction() ? null : $result['token']], 201);
    }

    public function resend(Request $request, CompanyInvitation $invitation): JsonResponse
    {
        $this->owned($request, $invitation);
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.invitations.manage');
        $this->access->assertCanAssignRoles($request->user(), $companyId, $invitation->role_ids);
        $this->invitations->revoke($invitation);
        $result = $this->invitations->invite($companyId, $invitation->email, $invitation->role_ids, $request->user());
        SendCompanyInvitation::dispatch($result['invitation']->id, Crypt::encryptString($result['token']))->afterCommit();

        return response()->json(['invitation' => $result['invitation'], 'acceptance_token' => app()->isProduction() ? null : $result['token']]);
    }

    public function revoke(Request $request, CompanyInvitation $invitation): JsonResponse
    {
        $this->owned($request, $invitation);
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.invitations.manage');
        $old = $invitation->toArray();
        $this->invitations->revoke($invitation);
        $this->audit->record($request, $request->user(), $companyId, 'invitation_revoked', 'platform', $invitation, $old, $invitation->fresh()->toArray());

        return response()->json($invitation->fresh());
    }

    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64'], 'name' => ['nullable', 'string', 'max:255'], 'password' => ['nullable', 'string', 'min:12']]);
        $result = $this->invitations->accept($data['token'], $request->user(), $data['name'] ?? null, $data['password'] ?? null);
        if (! $result['already_accepted']) {
            $this->audit->record($request, $result['user'], $result['membership']->company_id, 'invitation_accepted', 'platform', $result['membership'], null, $result['membership']->load('roles')->toArray());
        }

        return response()->json($result);
    }

    private function owned(Request $request, CompanyInvitation $invitation): void
    {
        abort_unless($invitation->company_id === $this->companyId($request), 404);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
