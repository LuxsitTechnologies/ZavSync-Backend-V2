<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Platform\EntitlementService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            SecurityEvent::query()->create([
                'type' => 'LOGIN_FAILED', 'result' => 'DENIED', 'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'correlation_id' => $request->attributes->get('correlation_id'), 'metadata' => ['email' => mb_strtolower($credentials['email'])],
            ]);
            throw ValidationException::withMessages(['email' => 'The provided credentials are incorrect.']);
        }
        $request->session()->regenerate();
        SecurityEvent::query()->create([
            'user_id' => $request->user()->id, 'type' => 'LOGIN_SUCCEEDED', 'result' => 'SUCCESS',
            'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]);

        return response()->json($this->userPayload($request));
    }

    public function current(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function requestPasswordReset(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        Password::sendResetLink(['email' => Str::lower($data['email'])]);

        return response()->json(['message' => 'If an account exists for that address, a reset link has been queued.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'], 'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        $status = Password::reset($data, function (User $user, string $password) use ($request): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            DB::table(config('session.table'))->where('user_id', $user->id)->delete();
            SecurityEvent::query()->create([
                'user_id' => $user->id, 'type' => 'PASSWORD_RESET', 'result' => 'SUCCESS',
                'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]);
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw new PlatformException('PASSWORD_RESET_INVALID', 'This password reset link is invalid or expired.', 422);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function switchCompany(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'uuid']]);
        if (! $request->user()->belongsToCompany($data['company_id'])) {
            abort(403, 'You do not have access to this company.');
        }

        return response()->json(['company' => $this->companyPayload($request->user()->id, $data['company_id'])]);
    }

    /** @return array<string, mixed> */
    private function userPayload(Request $request): array
    {
        $user = $request->user();

        $memberships = CompanyUser::query()->with(['company', 'role.permissions', 'roles.permissions'])
            ->where('user_id', $user->id)->where('is_active', true)->get();

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'is_platform_admin' => $user->is_platform_admin],
            'companies' => $memberships->map(fn (CompanyUser $membership): array => $this->companyPayload($user->id, $membership->company_id, $membership))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function companyPayload(int $userId, string $companyId, ?CompanyUser $membership = null): array
    {
        $membership ??= CompanyUser::query()->with(['company', 'role.permissions', 'roles.permissions'])
            ->where('company_id', $companyId)->where('user_id', $userId)->where('is_active', true)->firstOrFail();
        $permissions = collect([$membership->role])->merge($membership->roles)->filter()
            ->flatMap(fn ($role) => $role->permissions)->pluck('name')->unique()->values();

        return [
            'id' => $membership->company->id, 'name' => $membership->company->name,
            'currency' => $membership->company->currency, 'timezone' => $membership->company->timezone,
            'roles' => collect([$membership->role])->merge($membership->roles)->filter()->pluck('name')->unique()->values(),
            'permissions' => $permissions, 'modules' => $this->entitlements->enabledModules($companyId),
        ];
    }
}
