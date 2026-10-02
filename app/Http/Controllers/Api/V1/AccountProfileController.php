<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountProfileResource;
use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountProfileController extends Controller
{
    public function show(Request $request): AccountProfileResource
    {
        return new AccountProfileResource($request->user());
    }

    public function update(Request $request): AccountProfileResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['prohibited'],
            'password' => ['prohibited'],
            'is_platform_admin' => ['prohibited'],
            'company_id' => ['prohibited'],
            'role_id' => ['prohibited'],
        ]);
        $user = $request->user();
        if ($user->name !== $data['name']) {
            DB::transaction(function () use ($request, $user, $data): void {
                $user->update(['name' => $data['name']]);
                $this->securityEvent($request, 'ACCOUNT_PROFILE_UPDATED', ['fields' => ['name']]);
            });
        }

        return new AccountProfileResource($user->refresh());
    }

    public function changePassword(Request $request): AccountProfileResource
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed', 'different:current_password'],
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            $this->securityEvent($request, 'PASSWORD_CHANGE_REJECTED');
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        DB::transaction(function () use ($request, $user, $data): void {
            $user->forceFill(['password' => Hash::make($data['password'])])->save();
            $user->tokens()->delete();
            if (config('session.driver') === 'database') {
                $sessions = DB::table(config('session.table'))->where('user_id', $user->id);
                if ($request->hasSession()) {
                    $sessions->where('id', '!=', $request->session()->getId());
                }
                $sessions->delete();
            }
            $this->securityEvent($request, 'PASSWORD_CHANGED');
        });

        return new AccountProfileResource($user->refresh());
    }

    /** @param array<string, mixed>|null $metadata */
    private function securityEvent(Request $request, string $type, ?array $metadata = null): void
    {
        SecurityEvent::query()->create([
            'user_id' => $request->user()->id,
            'type' => $type,
            'result' => $type === 'PASSWORD_CHANGE_REJECTED' ? 'DENIED' : 'SUCCESS',
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'correlation_id' => $request->attributes->get('correlation_id'),
            'metadata' => $metadata,
        ]);
    }
}
