<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompany
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->header('X-Company-Id') ?: $request->query('company_id');

        if (! is_string($companyId) || $companyId === '') {
            return response()->json(['message' => 'Select a company before continuing.'], 422);
        }

        if (! $request->user()?->belongsToCompany($companyId)) {
            return response()->json(['message' => 'You do not have access to this company.'], 403);
        }

        $request->attributes->set('company_id', $companyId);

        return $next($request);
    }
}
