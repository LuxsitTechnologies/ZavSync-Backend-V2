<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\CompanyInvitation;
use App\Models\CompanyUser;
use App\Models\SecurityEvent;
use App\Services\AuditService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SecurityController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.security.view');
        $memberIds = CompanyUser::query()->where('company_id', $companyId)->pluck('user_id');
        $sessions = collect();
        if (config('session.driver') === 'database') {
            $sessions = DB::table(config('session.table'))->whereIn('user_id', $memberIds)->orderByDesc('last_activity')->limit(100)->get(['id', 'user_id', 'ip_address', 'user_agent', 'last_activity']);
        }

        return response()->json([
            'sessions_supported' => config('session.driver') === 'database',
            'sessions' => $sessions,
            'events' => SecurityEvent::query()->where(function ($query) use ($companyId, $memberIds): void {
                $query->where('company_id', $companyId)->orWhereIn('user_id', $memberIds);
            })->latest()->paginate(50),
            'invitations' => CompanyInvitation::query()->where('company_id', $companyId)->latest()->limit(50)->get(),
        ]);
    }

    public function revokeSession(Request $request, string $session): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.security.manage');
        abort_unless(config('session.driver') === 'database', 409, 'Session management is unavailable for this session driver.');
        $memberIds = CompanyUser::query()->where('company_id', $companyId)->pluck('user_id');
        $target = DB::table(config('session.table'))->where('id', $session)->whereIn('user_id', $memberIds)->first();
        abort_unless($target !== null, 404);
        abort_if($request->hasSession() && $session === $request->session()->getId(), 409, 'Use logout to revoke the current session.');
        DB::table(config('session.table'))->where('id', $session)->delete();
        $this->audit->record($request, $request->user(), $companyId, 'session_revoked', 'platform', $request->user(), ['session_id' => $session, 'user_id' => $target->user_id], null);

        return response()->json(['message' => 'Session revoked.']);
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        abort_unless(config('session.driver') === 'database', 409, 'Session management is unavailable for this session driver.');
        $sessions = DB::table(config('session.table'))->where('user_id', $request->user()->id);
        if ($request->hasSession()) {
            $sessions->where('id', '!=', $request->session()->getId());
        }
        $revoked = $sessions->delete();
        $this->audit->record($request, $request->user(), $this->companyId($request), 'other_sessions_revoked', 'platform', $request->user(), ['revoked_count' => $revoked], null);

        return response()->json(['message' => 'Other sessions revoked.']);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
