<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Web panel: `role:admin,therapist`. App users get 403 forbidden. */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array($request->user()?->role, $roles, true)) {
            throw new ApiException('forbidden', 'You don\'t have access to this.', 403);
        }

        return $next($request);
    }
}
