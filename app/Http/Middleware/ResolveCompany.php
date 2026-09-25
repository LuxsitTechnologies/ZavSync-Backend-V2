<?php

namespace App\Http\Middleware;

use App\Exceptions\PlatformException;
use App\Services\Platform\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompany
{
    public function __construct(private readonly EntitlementService $entitlements) {}

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

        $request->attributes->set('company_id', $companyId);
        $this->entitlements->enforceRequest($companyId, $request->path());

        return $next($request);
    }
}
