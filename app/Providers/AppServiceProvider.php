<?php

namespace App\Providers;

use App\Contracts\FbrGateway;
use App\Contracts\OutboundEmailGateway;
use App\Contracts\OutreachAiAssistant;
use App\Services\Fbr\HttpFbrGateway;
use App\Services\Outreach\SmtpEmailGateway;
use App\Services\Outreach\UnavailableOutreachAiAssistant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FbrGateway::class, HttpFbrGateway::class);
        $this->app->bind(OutboundEmailGateway::class, SmtpEmailGateway::class);
        $this->app->bind(OutreachAiAssistant::class, UnavailableOutreachAiAssistant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();
        Model::preventLazyLoading(! app()->isProduction());
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip().'|'.$request->string('email')->lower()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perHour(5)->by($request->ip().'|'.$request->string('email')->lower()));
        RateLimiter::for('invitations', fn (Request $request) => Limit::perMinute(10)->by(($request->user()?->id ?? $request->ip()).'|'.$request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by(($request->user()?->id ?? $request->ip()).'|'.$request->header('X-Company-Id')));
        RateLimiter::for('company-switch', fn (Request $request) => Limit::perMinute(30)->by((string) ($request->user()?->id ?? $request->ip())));
        RateLimiter::for('company-create', fn (Request $request) => Limit::perHour(5)->by((string) ($request->user()?->id ?? $request->ip())));
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(20)->by(($request->user()?->id ?? $request->ip()).'|'.$request->header('X-Company-Id')));
    }
}
