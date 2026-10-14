<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\FbrCompanyConfiguration;
use App\Models\FbrReferenceValue;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Platform\CompanyAdminRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CompanyController extends Controller
{
    public function __construct(private readonly AuditService $audit, private readonly CompanyAdminRoleService $adminRoles) {}

    public function onboardingPlans(): JsonResponse
    {
        return response()->json([
            'plans' => Plan::query()->where('is_active', true)
                ->whereIn('code', ['starter', 'professional', 'enterprise'])
                ->orderBy('id')->get(['id', 'code', 'name']),
            'trial_days_after_approval' => 14,
            'checkout_available' => false,
            'catalogue_approved' => true,
            'pricing_certified' => false,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            throw new PlatformException('EMAIL_VERIFICATION_REQUIRED', 'Verify your email before creating a company.', 403);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255', "regex:/^[\\p{L}\\p{N}\\s&.,'’\\-()\\/@+#]+$/u", 'regex:/\p{L}/u'], 'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'timezone'], 'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where(fn ($query) => $query
                ->whereIn('code', ['starter', 'professional', 'enterprise']))],
            'company_size' => ['nullable', 'string', 'max:100'], 'province_code' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:2000'], 'tax_identifier' => ['nullable', 'regex:/^[A-Za-z0-9]{7,13}$/'],
            'fbr_token' => ['nullable', 'string', 'min:8', 'max:4000'],
            'fbr_environment' => ['required_with:fbr_token', Rule::in(['SANDBOX', 'PRODUCTION'])],
        ]);
        if (! empty($data['fbr_token'])) {
            $request->validate([
                'tax_identifier' => ['required', 'regex:/^(?:[0-9]{7}|[0-9]{13})$/'],
                'province_code' => ['required', Rule::exists(FbrReferenceValue::class, 'code')->where(fn ($query) => $query->where('category', 'PROVINCE')->where('is_active', true))],
                'address' => ['required', 'string', 'max:2000'],
            ]);
        }
        $key = $request->header('Idempotency-Key');
        if ($key !== null && (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $key))) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Idempotency-Key header is required when supplied.']);
        }
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        [$company, $membership, $created] = DB::transaction(function () use ($data, $request, $key, $hash): array {
            User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if ($key !== null) {
                $existing = Company::query()->where('created_by', $request->user()->id)->where('creation_idempotency_key', $key)->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->creation_idempotency_hash, $hash)) {
                        throw new ConflictHttpException('The company creation idempotency key belongs to a different request.');
                    }

                    return [$existing, CompanyUser::query()->where('company_id', $existing->id)->where('user_id', $request->user()->id)->firstOrFail(), false];
                }
            }
            $plan = Plan::query()->whereKey($data['plan_id'])->where('is_active', true)
                ->whereIn('code', ['starter', 'professional', 'enterprise'])->lockForUpdate()->first();
            if ($plan === null) {
                throw ValidationException::withMessages(['plan_id' => 'The selected plan is not available for new company onboarding.']);
            }
            $baseSlug = Str::slug($data['name']) ?: 'company';
            $slug = $baseSlug;
            $suffix = 1;
            while (Company::query()->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$suffix++;
            }
            $company = Company::query()->create([
                'name' => $data['name'], 'slug' => $slug, 'currency' => strtoupper($data['currency']), 'timezone' => $data['timezone'],
                'is_active' => true, 'approval_status' => 'PENDING', 'company_size' => $data['company_size'] ?? null,
                'province_code' => $data['province_code'] ?? null, 'created_by' => $request->user()->id,
                'billing_policy_applies' => true,
                'creation_idempotency_key' => $key, 'creation_idempotency_hash' => $key === null ? null : $hash,
            ]);
            CompanySetting::query()->create([
                'company_id' => $company->id, 'legal_name' => $company->name, 'country_code' => strtoupper($data['country_code']),
                'billing_country_confirmed_at' => now(),
                'timezone' => $company->timezone, 'base_currency' => $company->currency,
                'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null, 'tax_identifier' => $data['tax_identifier'] ?? null,
                'updated_by' => $request->user()->id,
            ]);
            if (! empty($data['fbr_token'])) {
                FbrCompanyConfiguration::query()->create([
                    'company_id' => $company->id,
                    'seller_tax_identifier' => $data['tax_identifier'],
                    'seller_business_name' => $data['name'],
                    'seller_province' => $data['province_code'],
                    'seller_address' => $data['address'],
                    'environment' => $data['fbr_environment'],
                    'credential' => $data['fbr_token'],
                    'connection_state' => 'NOT_VERIFIED',
                    'updated_by' => $request->user()->id,
                ]);
            }
            $role = $this->adminRoles->provision($company->id);
            $membership = CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $request->user()->id, 'role_id' => $role->id, 'is_active' => true]);
            $membership->roles()->attach($role->id);
            Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'PENDING_APPROVAL', 'billing_interval' => $plan->billing_interval, 'starts_at' => null, 'trial_ends_at' => null]);
            $this->audit->record($request, $request->user(), $company->id, 'company_created', 'platform', $company, null, $company->toArray());

            return [$company, $membership, true];
        });

        return response()->json(['company' => $company, 'membership' => $membership, 'trial_ends_at' => $company->subscriptions()->first()?->trial_ends_at?->toIso8601String()], $created ? 201 : 200);
    }
}
