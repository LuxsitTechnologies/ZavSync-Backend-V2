<?php

namespace App\Http\Middleware;

use App\Exceptions\PlatformException;
use App\Models\Company;
use App\Services\Platform\CommercialStateService;
use App\Services\Platform\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompany
{
    public function __construct(private readonly EntitlementService $entitlements,
        private readonly CommercialStateService $commercialState) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->header('X-Company-Id') ?: $request->query('company_id');

        if (! is_string($companyId) || $companyId === '') {
            throw new PlatformException('COMPANY_CONTEXT_REQUIRED', 'Select a company before continuing.', 422);
        }

        if (! $request->user()?->belongsToCompany($companyId)) {
            throw new PlatformException('TENANT_ACCESS_DENIED', 'You do not have access to this company.', 403);
        }
        $company = Company::query()->findOrFail($companyId);
        if ($company->approval_status !== 'APPROVED') {
            throw new PlatformException('COMPANY_NOT_APPROVED', 'This company is not approved for workspace access.', 403);
        }
        if (! $company->is_active) {
            throw new PlatformException('COMPANY_INACTIVE', 'This company is inactive.', 403);
        }

        $request->attributes->set('company_id', $companyId);
        $commercialState = $company->billing_policy_applies ? $this->commercialState->describe($company)['state'] : null;
        if (in_array($commercialState, ['READ_ONLY', 'PAYMENT_PENDING'], true)) {
            if (str_starts_with($request->path(), 'api/v1/ai/') || ! $this->allowedReadOnlyRequest($request)) {
                throw new PlatformException('COMPANY_READ_ONLY', 'The company is in limited read-only mode until billing is active.', 403);
            }
        } else {
            $this->entitlements->enforceRequest($companyId, $request->path());
        }

        return $next($request);
    }

    private function allowedReadOnlyRequest(Request $request): bool
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || $request->isMethod('OPTIONS')) {
            return true;
        }

        return $request->routeIs('platform.billing.quote', 'platform.billing.country', 'platform.billing.checkout',
            'platform.billing.checkout.reconcile', 'platform.billing.payment-method-portal',
            'platform.billing.plan-change.reconcile', 'platform.billing.components.cancel',
            'pakistan-fbr.invoices.reconcile', 'pakistan-fbr.invoices.resolve',
            'pakistan-fbr.credit-notes.reconcile',
            'platform.company-profile.update', 'platform.admin.companies.*',
            'platform.notifications.read', 'platform.notifications.read-all');
    }
}
