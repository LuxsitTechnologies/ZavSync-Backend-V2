<?php

use App\Http\Middleware\AddRequestContext;
use App\Http\Middleware\ResolveCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $identifiers = ['source_system', 'source_id', 'source_company_id', 'legacy_source_system', 'legacy_source_id',
            'legacy_original_company_id', 'idempotency_key', 'creation_idempotency_key', 'origin_idempotency_key',
            'posting_idempotency_key', 'conversion_idempotency_key', 'customer_handoff_key', 'invoice_number',
            'supplier_invoice_number', 'fbr_invoice_number', 'fbr_reference_number', 'reference_number'];
        $middleware->trimStrings(except: [...$identifiers, ...array_map(fn (string $field): string => '*.'.$field, $identifiers)]);
        $middleware->append(AddRequestContext::class);
        $middleware->alias([
            'company' => ResolveCompany::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
