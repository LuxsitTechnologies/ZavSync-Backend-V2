<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformException;
use App\Models\CompanyInvitation;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InvitationService
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @param array<int, int> $roleIds @return array{invitation:CompanyInvitation,token:string} */
    public function invite(string $companyId, string $email, array $roleIds, User $inviter): array
    {
        $email = Str::lower($email);
        $currentUsers = CompanyUser::query()->where('company_id', $companyId)->where('is_active', true)->count()
            + CompanyInvitation::query()->where('company_id', $companyId)->where('status', 'PENDING')->where('expires_at', '>', now())->count();
        $this->entitlements->assertWithinLimit($companyId, 'users', $currentUsers);
        if (CompanyInvitation::query()->where('company_id', $companyId)->where('email', $email)->where('status', 'PENDING')->where('expires_at', '>', now())->exists()) {
            throw new PlatformException('INVITATION_ALREADY_PENDING', 'An active invitation already exists for this email.', 409);
        }
        if (CompanyUser::query()->where('company_id', $companyId)->whereHas('user', fn ($query) => $query->where('email', $email))->where('is_active', true)->exists()) {
            throw new PlatformException('MEMBERSHIP_ALREADY_EXISTS', 'This user is already an active company member.', 409);
        }

        $validRoles = Role::query()->where('company_id', $companyId)->whereNull('archived_at')->whereKey($roleIds)->pluck('id')->all();
        if (count($validRoles) !== count(array_unique($roleIds))) {
            throw new PlatformException('TENANT_ACCESS_DENIED', 'One or more selected roles do not belong to this company.', 403);
        }

        $token = Str::random(64);
        $invitation = CompanyInvitation::query()->create([
            'company_id' => $companyId, 'email' => $email, 'token_hash' => hash('sha256', $token),
            'status' => 'PENDING', 'role_ids' => $validRoles, 'invited_by' => $inviter->id, 'expires_at' => now()->addDays(7),
        ]);

        return compact('invitation', 'token');
    }

    /** @return array{membership:CompanyUser,user:User,already_accepted:bool} */
    public function accept(string $token, ?User $authenticatedUser, ?string $name, ?string $password): array
    {
        return DB::transaction(function () use ($token, $authenticatedUser, $name, $password): array {
            $invitation = CompanyInvitation::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if ($invitation === null) {
                throw new PlatformException('INVITATION_INVALID', 'This invitation is invalid.', 404);
            }
            if ($invitation->status === 'REVOKED') {
                throw new PlatformException('INVITATION_REVOKED', 'This invitation has been revoked.', 410);
            }
            if ($invitation->status === 'EXPIRED' || $invitation->expires_at->isPast()) {
                $invitation->update(['status' => 'EXPIRED']);
                throw new PlatformException('INVITATION_EXPIRED', 'This invitation has expired.', 410);
            }

            if ($authenticatedUser !== null && Str::lower($authenticatedUser->email) !== $invitation->email) {
                throw new PlatformException('INVITATION_EMAIL_MISMATCH', 'Sign in with the invited email address.', 403);
            }
            $existingUser = User::query()->where('email', $invitation->email)->first();
            if ($authenticatedUser === null && $existingUser !== null) {
                throw new PlatformException('INVITATION_AUTHENTICATION_REQUIRED', 'Sign in before accepting this invitation.', 401);
            }
            $user = $authenticatedUser;
            if ($user === null) {
                if ($name === null || $password === null) {
                    throw new PlatformException('INVITATION_REGISTRATION_REQUIRED', 'Name and password are required for a new user.', 422);
                }
                $user = User::query()->create(['name' => $name, 'email' => $invitation->email, 'password' => Hash::make($password), 'email_verified_at' => now()]);
            }

            $membership = CompanyUser::query()->firstOrCreate(
                ['company_id' => $invitation->company_id, 'user_id' => $user->id],
                ['role_id' => $invitation->role_ids[0] ?? null, 'is_active' => true],
            );
            $membership->update(['is_active' => true, 'suspended_at' => null, 'suspended_by' => null]);
            $membership->roles()->sync($invitation->role_ids);
            $alreadyAccepted = $invitation->status === 'ACCEPTED';
            if (! $alreadyAccepted) {
                $invitation->update(['status' => 'ACCEPTED', 'accepted_at' => now()]);
            }

            return ['membership' => $membership, 'user' => $user, 'already_accepted' => $alreadyAccepted];
        });
    }

    public function revoke(CompanyInvitation $invitation): CompanyInvitation
    {
        if ($invitation->status === 'ACCEPTED') {
            throw new PlatformException('INVITATION_ALREADY_ACCEPTED', 'An accepted invitation cannot be revoked.', 409);
        }
        $invitation->update(['status' => 'REVOKED', 'revoked_at' => now()]);

        return $invitation;
    }
}
