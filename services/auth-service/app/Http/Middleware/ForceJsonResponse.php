<?php

namespace RifaCloud\AuthService\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The auth-service is an API only: every request must negotiate JSON.
 *
 * Without this, an unauthenticated request that does not send
 * `Accept: application/json` (a browser navigation, an old client, a health
 * probe) makes Laravel try to redirect to a non-existent `login` route and
 * return a 500. The SPA uses 401/403 to decide whether the session is valid,
 * so the API must never answer a redirect/500 for those cases.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
