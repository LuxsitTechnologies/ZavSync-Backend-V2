<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AddRequestContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = $request->header('X-Request-Id');
        $requestId = is_string($candidate) && Str::isUuid($candidate) ? $candidate : (string) Str::uuid();
        $context = ['request_id' => $requestId, 'route' => $request->path()];
        if ($request->user() !== null) {
            $context['user_id'] = $request->user()->getKey();
        }
        if (is_string($request->header('X-Company-Id'))) {
            $context['company_id'] = $request->header('X-Company-Id');
        }
        Context::add($context);
        Log::withContext($context);
        $request->attributes->set('correlation_id', $requestId);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
