<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\FbrReferenceValue;
use App\Models\Subscription;
use App\Services\AuditService;
use App\Services\Platform\BillingCancellationService;
use App\Services\Platform\CompanySettingsService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\NotificationService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CompanyAdministrationController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly AuditService $audit, private readonly CompanySettingsService $settings, private readonly EntitlementService $entitlements, private readonly NotificationService $notifications, private readonly BillingCancellationService $billingCancellation) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize($request, 'platform.companies.view');
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'], 'approval_status' => ['nullable', Rule::in(['PENDING', 'APPROVED', 'REJECTED'])],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $query = Company::query()->with('settings')->withExists(['fbrConfiguration as fbr_credential_configured' => fn (Builder $query): Builder => $query->whereNotNull('credential')]);
        if (isset($data['search']) && trim($data['search']) !== '') {
            $search = '%'.trim($data['search']).'%';
            $query->where(fn (Builder $builder): Builder => $builder->where('name', 'like', $search)
                ->orWhereHas('settings', fn (Builder $settings): Builder => $settings
                    ->where('email', 'like', $search)->orWhere('phone', 'like', $search)
                    ->orWhere('address', 'like', $search)->orWhere('tax_identifier', 'like', $search)));
        }
        if (isset($data['approval_status'])) {
            $query->where('approval_status', $data['approval_status']);
        }
        if (isset($data['is_active'])) {
            $query->where('is_active', $data['is_active']);
        }
        $companies = $query->orderByDesc('created_at')->orderBy('id')->paginate($data['per_page'] ?? 10);

        return response()->json($companies->through(fn (Company $company): array => $this->profile($company)));
    }

    public function show(Request $request, string $company): JsonResponse
    {
        $this->authorize($request, 'platform.companies.view');

        return response()->json($this->profile(Company::query()->with('settings')->withExists(['fbrConfiguration as fbr_credential_configured' => fn (Builder $query): Builder => $query->whereNotNull('credential')])->findOrFail($company)));
    }

    public function ownProfile(Request $request): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'platform.settings.view');

        return response()->json($this->profile(Company::query()->with('settings')->withExists(['fbrConfiguration as fbr_credential_configured' => fn (Builder $query): Builder => $query->whereNotNull('credential')])->findOrFail($companyId)));
    }

    public function updateOwnProfile(Request $request): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'platform.settings.manage');

        return $this->updateProfile($request, $companyId);
    }

    public function updateAdminProfile(Request $request, string $company): JsonResponse
    {
        $this->authorize($request, 'platform.companies.view');
        $this->access->authorize($request->user(), $company, 'platform.settings.manage');

        return $this->updateProfile($request, $company);
    }

    public function pendingProfile(Request $request, string $company): JsonResponse
    {
        $model = $this->pendingOwnedCompany($request, $company);
        $this->access->authorize($request->user(), $model->id, 'platform.settings.view');

        return response()->json($this->profile($model->load('settings')->loadExists(['fbrConfiguration as fbr_credential_configured' => fn (Builder $query): Builder => $query->whereNotNull('credential')])));
    }

    public function updatePendingProfile(Request $request, string $company): JsonResponse
    {
        $model = $this->pendingOwnedCompany($request, $company);
        $this->access->authorize($request->user(), $model->id, 'platform.settings.manage');

        return $this->updateProfile($request, $model->id);
    }

    private function pendingOwnedCompany(Request $request, string $company): Company
    {
        $model = Company::query()->findOrFail($company);
        if ($model->created_by !== $request->user()->id || ! in_array($model->approval_status, ['PENDING', 'REJECTED'], true)
            || ! $model->is_active || ! $request->user()->belongsToCompany($model->id)) {
            abort(404);
        }

        return $model;
    }

    private function updateProfile(Request $request, string $companyId): JsonResponse
    {
        $data = $request->validate([
            'profile_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255', "regex:/^[\\p{L}\\p{N}\\s&.,'’\\-()\\/@+#]+$/u", 'regex:/\p{L}/u'],
            'company_size' => ['nullable', 'string', 'max:100'], 'province_code' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:2000'], 'tax_identifier' => ['nullable', 'regex:/^[A-Za-z0-9]{7,13}$/'],
        ]);
        $company = DB::transaction(function () use ($request, $companyId, $data): Company {
            $model = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            if ($request->routeIs('platform.companies.pending-profile.update')
                && ($model->created_by !== $request->user()->id || ! in_array($model->approval_status, ['PENDING', 'REJECTED'], true) || ! $model->is_active)) {
                abort(404);
            }
            if ($model->profile_version !== $data['profile_version']) {
                throw new PlatformException('COMPANY_PROFILE_VERSION_STALE', 'The company profile changed; reload it before saving.', 409);
            }
            $before = $this->profile($model->load('settings')->loadExists(['fbrConfiguration as fbr_credential_configured' => fn (Builder $query): Builder => $query->whereNotNull('credential')]));
            $settings = collect($data)->only(['email', 'phone', 'address', 'tax_identifier'])->all();
            if (isset($data['name'])) {
                $settings['legal_name'] = $data['name'];
            }
            if ($settings !== []) {
                $this->settings->update($model, $settings, $request->user());
            }
            $model->update([
                'company_size' => array_key_exists('company_size', $data) ? $data['company_size'] : $model->company_size,
                'province_code' => array_key_exists('province_code', $data) ? $data['province_code'] : $model->province_code,
                'profile_version' => $this->nextVersion($model),
            ]);
            if ($model->approval_status === 'APPROVED') {
                $this->assertApprovalReady($model);
            }
            $model = $model->fresh()->load('settings')->loadExists(['fbrConfiguration as fbr_credential_configured' => fn (Builder $query): Builder => $query->whereNotNull('credential')]);
            $this->audit->record($request, $request->user(), $companyId, 'company_profile_updated', 'platform', $model,
                $before, $this->profile($model));

            return $model;
        });

        return response()->json($this->profile($company));
    }

    public function approval(Request $request, string $company): JsonResponse
    {
        $this->authorize($request, 'platform.companies.approve');
        $data = $request->validate(['approval_status' => ['required', Rule::in(['APPROVED', 'REJECTED'])], 'profile_version' => ['required', 'integer', 'min:1']]);
        $result = DB::transaction(function () use ($request, $company, $data): Company {
            $model = Company::query()->lockForUpdate()->findOrFail($company);
            if ($model->approval_status !== $data['approval_status']) {
                $this->assertVersion($model, $data['profile_version']);
                if ($data['approval_status'] === 'APPROVED') {
                    $this->assertApprovalReady($model);
                }
                $previous = $model->approval_status;
                $model->update(['approval_status' => $data['approval_status'], 'profile_version' => $this->nextVersion($model)]);
                if ($data['approval_status'] === 'APPROVED') {
                    $pending = Subscription::query()->where('company_id', $model->id)->where('status', 'PENDING_APPROVAL')->lockForUpdate()->first();
                    if ($pending !== null) {
                        $approvedAt = now();
                        $pending->update(['status' => 'TRIALING', 'starts_at' => $approvedAt, 'trial_ends_at' => $approvedAt->copy()->addDays(14)]);
                    }
                }
                $this->audit->record($request, $request->user(), $model->id, 'company_approval_changed', 'platform', $model,
                    ['approval_status' => $previous], ['approval_status' => $model->approval_status]);
                if ($model->created_by !== null) {
                    $approved = $model->approval_status === 'APPROVED';
                    $this->notifications->create($model->id, $model->created_by, 'platform.company.approval',
                        $approved ? 'Company approved' : 'Company request rejected',
                        $approved ? 'Your company workspace is approved and its trial has started.' : 'Your company request was rejected. Contact a platform administrator for details.',
                        ['company_id' => $model->id, 'approval_status' => $model->approval_status]);
                }
            }

            return $model->load('settings')->loadExists(['fbrConfiguration as fbr_credential_configured' => fn (Builder $query): Builder => $query->whereNotNull('credential')]);
        });
        $this->entitlements->forget($result->id);

        return response()->json($this->profile($result));
    }

    public function activity(Request $request, string $company): JsonResponse
    {
        $this->authorize($request, 'platform.companies.activate');
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'profile_version' => ['required', 'integer', 'min:1']]);
        if (! $data['is_active'] && $company === ($request->header('X-Company-Id') ?: $request->query('company_id'))) {
            throw new PlatformException('COMPANY_CONTEXT_DEACTIVATION_BLOCKED', 'Select a different active administration company before deactivating this one.', 409);
        }
        $result = DB::transaction(function () use ($request, $company, $data): Company {
            $model = Company::query()->lockForUpdate()->findOrFail($company);
            if ($model->is_active !== $data['is_active']) {
                $this->assertVersion($model, $data['profile_version']);
                if (! $data['is_active']) {
                    $paidSubscriptions = Subscription::query()->where('company_id', $model->id)
                        ->where('provider', 'stripe')->whereIn('status', ['ACTIVE', 'PAST_DUE_GRACE'])
                        ->whereNotNull('provider_reference')
                        ->lockForUpdate()->get();
                    if ($paidSubscriptions->count() > 1) {
                        throw new PlatformException('BILLING_SUBSCRIPTION_REVIEW_REQUIRED', 'Multiple paid subscriptions require review before deactivation.', 409);
                    }
                    if ($paidSubscriptions->first() !== null
                        && $paidSubscriptions->first()->status !== 'ACTIVE'
                        && ! $paidSubscriptions->first()->cancel_at_period_end_at?->isFuture()) {
                        throw new PlatformException('BILLING_SUBSCRIPTION_REVIEW_REQUIRED', 'The paid subscription requires review before deactivation.', 409);
                    }
                    $this->billingCancellation->ensureComponentsScheduledForCancellation($model->id, $request->user());
                    if ($paidSubscriptions->first() !== null
                        && ! $paidSubscriptions->first()->cancel_at_period_end_at?->isFuture()) {
                        $this->billingCancellation->cancelAtPeriodEnd($model->id, $request->user());
                    }
                }
                $previous = $model->is_active;
                $model->update(['is_active' => $data['is_active'], 'profile_version' => $this->nextVersion($model)]);
                $this->audit->record($request, $request->user(), $model->id, 'company_activity_changed', 'platform', $model,
                    ['is_active' => $previous], ['is_active' => $model->is_active]);
            }

            return $model->load('settings')->loadExists(['fbrConfiguration as fbr_credential_configured' => fn (Builder $query): Builder => $query->whereNotNull('credential')]);
        });

        return response()->json($this->profile($result));
    }

    private function authorize(Request $request, string $permission): void
    {
        abort_unless($request->user()->is_platform_admin, 403, 'Platform administrator access is required.');
        $this->access->authorize($request->user(), (string) $request->attributes->get('company_id'), $permission);
    }

    private function nextVersion(Company $company): int
    {
        if ($company->profile_version >= PHP_INT_MAX) {
            throw new PlatformException('COMPANY_PROFILE_VERSION_EXHAUSTED', 'The company profile version is exhausted.', 409);
        }

        return $company->profile_version + 1;
    }

    private function assertApprovalReady(Company $company): void
    {
        $settings = $company->settings()->first();
        if ($settings === null || blank($settings->email) || blank($settings->tax_identifier) || blank($company->province_code)
            || ! preg_match('/^[A-Za-z0-9]{7,13}$/', (string) $settings->tax_identifier)
            || ! FbrReferenceValue::query()->where('category', 'PROVINCE')->where('code', $company->province_code)->where('is_active', true)->exists()) {
            throw new PlatformException('COMPANY_PROFILE_INCOMPLETE', 'A company email, valid NTN/CNIC and supported province are required before approval.', 409);
        }
    }

    private function assertVersion(Company $company, int $expectedVersion): void
    {
        if ($company->profile_version !== $expectedVersion) {
            throw new PlatformException('COMPANY_PROFILE_VERSION_STALE', 'The company profile changed; reload it before saving.', 409);
        }
    }

    /** @return array<string, mixed> */
    private function profile(Company $company): array
    {
        return [
            'id' => $company->id, 'name' => $company->name, 'company_size' => $company->company_size,
            'province_code' => $company->province_code, 'currency' => $company->currency, 'timezone' => $company->timezone,
            'approval_status' => $company->approval_status, 'is_active' => $company->is_active,
            'profile_version' => $company->profile_version, 'email' => $company->settings?->email,
            'phone' => $company->settings?->phone, 'address' => $company->settings?->address,
            'tax_identifier' => $company->settings?->tax_identifier,
            'fbr_credential_configured' => (bool) $company->fbr_credential_configured,
            'created_at' => $company->created_at?->toISOString(),
        ];
    }
}
