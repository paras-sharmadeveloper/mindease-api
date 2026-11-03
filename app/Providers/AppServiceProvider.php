<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
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

        RateLimiter::for('photo', fn (Request $request) => Limit::perHour(20)->by('photo:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(180)->by($request->user()?->id ?: $request->ip()));

        // Scramble serves /docs/api outside `local` only when this gate passes (same switch as /developers).
        Gate::define('viewApiDocs', fn ($user = null) => (bool) config('mindease.developer_docs'));

        // OpenAPI docs at /docs/api (local env only by default).
        // Two separate documents: the mobile app (/docs/api) and the web panel (/docs/panel). Neither lists the other's routes.
        if (class_exists(Scramble::class)) {
            Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));
            });

            Scramble::configure()->routes(fn (Route $route) => Str::startsWith($route->uri, 'v1/')
                && ! Str::startsWith($route->uri, ['v1/panel', 'v1/webhooks']));

            // The panel signs in with the same /auth endpoints as the app; only those three are listed here.
            Scramble::registerApi('panel', [
                'info' => [
                    'version' => env('API_VERSION', '1.0.0'),
                    'description' => <<<'MD'
                        **Web panel (therapists and admins) only.** The React panel in `mindease-web`.

                        The mobile app has its own, separate API docs at [`/docs/api`](/docs/api). Don't use the app's `/me/*`
                        or `/therapists*` endpoints from the panel; use the `/panel/*` ones listed here.

                        - Sign in with **Auth** (same tokens as the app), then call `GET /panel/me`. App users get `403`.
                        - Roles: `admin` sees everything; `therapist` sees only their own profile, hours and sessions.
                          Programs and Questionnaire are admin only.
                        - Errors: `{ "error": { "code", "message", "field" } }`. Validation errors add `error.fields`.
                        MD,
                ],
                'ui' => ['title' => 'MindEase Web Panel API'],
            ])
                ->routes(fn (Route $route) => Str::startsWith($route->uri, ['v1/panel', 'v1/webhooks'])
                    || in_array($route->uri, ['v1/auth/login', 'v1/auth/refresh', 'v1/auth/logout'], true))
                ->expose(ui: 'docs/panel', document: 'docs/panel.json');
        }
    }
}
