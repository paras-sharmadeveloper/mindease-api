<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request (memoises decoded program + config).
        $this->app->scoped(\App\Services\ContentRepository::class);

        $this->app->singleton(\App\Services\Payments\PaymentGateway::class, fn () => new \App\Services\Payments\StripeGateway(
            new \Stripe\StripeClient(['api_key' => config('services.stripe.secret') ?: null]),
        ));
    }

    public function boot(): void
    {
        // Resources return the object itself, not { "data": ... } (matches the contract).
        JsonResource::withoutWrapping();

        $emailKey = fn (Request $request) => Str::lower((string) $request->input('email')).'|'.$request->ip();

        // Contract: rate-limit login and reset.
        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.$emailKey($request)),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('auth-reset', fn (Request $request) => [
            Limit::perHour(5)->by('reset:'.$emailKey($request)),
            Limit::perMinute(10)->by('reset-ip:'.$request->ip()),
        ]);

        RateLimiter::for('auth-general', fn (Request $request) => Limit::perMinute(30)->by('auth:'.$request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(180)->by($request->user()?->id ?: $request->ip()));

        // Scramble serves /docs/api outside `local` only when this gate passes (same switch as /developers).
        Gate::define('viewApiDocs', fn ($user = null) => (bool) config('mindease.developer_docs'));

        // OpenAPI docs at /docs/api (local env only by default).
        if (class_exists(Scramble::class)) {
            Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));
            });
        }
    }
}
