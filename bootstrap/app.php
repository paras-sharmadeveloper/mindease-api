<?php

use App\Exceptions\ApiException;
use App\Support\ApiError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'idempotent' => \App\Http\Middleware\EnsureIdempotency::class,
        ]);

        // Mobile API: never redirect unauthenticated requests to a login page.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every response under /v1 uses the contract error shape:
        // { "error": { "code": "...", "message": "...", "field": "email|null" } }
        $isApi = fn (Request $request) => $request->is('v1/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);

        // Activity / questionnaire answers must never end up in logs.
        $exceptions->dontFlash(['password', 'password_confirmation', 'answers', 'refreshToken', 'token']);

        $exceptions->render(function (ApiException $e, Request $request) {
            return ApiError::response($e->errorCode, $e->getMessage(), $e->status, $e->field);
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            $errors = $e->errors();
            $field = array_key_first($errors);

            return ApiError::response('validation_failed', $errors[$field][0] ?? 'The request is invalid.', 422, $field, $errors);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiError::response('unauthenticated', 'Please sign in again.', 401)
                : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiError::response('rate_limited', 'Too many attempts. Please wait a moment and try again.', 429)
                    ->withHeaders($e->getHeaders())
                : null;
        });

        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiError::response('not_found', 'We couldn\'t find that.', 404)
                : null;
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiError::response('method_not_allowed', 'This action isn\'t supported here.', 405)
                : null;
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiError::response('http_error', $e->getMessage() ?: 'Something went wrong.', $e->getStatusCode())
                : null;
        });

        $exceptions->render(function (\Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request) || config('app.debug')) {
                return null;
            }

            return ApiError::response('server_error', 'Something went wrong on our side. Please try again.', 500);
        });
    })->create();
