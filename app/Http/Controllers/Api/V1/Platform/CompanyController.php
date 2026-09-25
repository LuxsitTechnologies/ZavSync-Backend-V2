<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'timezone'], 'country_code' => ['required', 'string', 'size:2'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);
        $plan = Plan::query()->whereKey($data['plan_id'])->where('is_active', true)->firstOrFail();
        [$company, $membership] = DB::transaction(function () use ($data, $plan, $request): array {
            $baseSlug = Str::slug($data['name']);
            $slug = $baseSlug;
            $suffix = 1;
            while (Company::query()->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$suffix++;
            }
            $company = Company::query()->create(['name' => $data['name'], 'slug' => $slug, 'currency' => strtoupper($data['currency']), 'timezone' => $data['timezone'], 'is_active' => true]);
            CompanySetting::query()->create(['company_id' => $company->id, 'legal_name' => $company->name, 'country_code' => strtoupper($data['country_code']), 'timezone' => $company->timezone, 'base_currency' => $company->currency, 'updated_by' => $request->user()->id]);
            $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Company Administrator', 'is_system' => true]);
            $platformOnly = $request->user()->is_platform_admin ? [] : ['platform.plans.manage', 'platform.jobs.view', 'platform.jobs.manage'];
            $role->permissions()->sync(Permission::query()->whereNotIn('name', $platformOnly)->pluck('id'));
            $membership = CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $request->user()->id, 'role_id' => $role->id, 'is_active' => true]);
            $membership->roles()->attach($role->id);
            Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'TRIALING', 'billing_interval' => $plan->billing_interval, 'starts_at' => now(), 'trial_ends_at' => now()->addDays(14)]);
            $this->audit->record($request, $request->user(), $company->id, 'company_created', 'platform', $company, null, $company->toArray());

            return [$company, $membership];
        });

        return response()->json(['company' => $company, 'membership' => $membership, 'trial_ends_at' => now()->addDays(14)->toIso8601String()], 201);
    }
}
