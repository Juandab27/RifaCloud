<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Session-cookie authentication for the React SPA (Sanctum, no tokens).
        // The session table, APP_KEY and cookie name match the monolith so a
        // session issued here is accepted by the existing protected routes.
        $middleware->statefulApi();

        // Trust the scheme forwarded by ALB/Nginx when the origin is restricted
        // to that proxy. Direct Nginx TLS uses HTTPS=on instead.
        $trustedProxies = env('TRUSTED_PROXIES');

        if (is_string($trustedProxies) && trim($trustedProxies) !== '') {
            $middleware->trustProxies(
                at: trim($trustedProxies) === '*'
                    ? '*'
                    : array_map('trim', explode(',', $trustedProxies)),
            );
        }

        // The service is API-only: force JSON negotiation so unauthenticated
        // requests return 401 instead of redirecting to a missing login route.
        $middleware->api(prepend: [
            \RifaCloud\AuthService\Http\Middleware\ForceJsonResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Never attempt a redirect to a non-existent "login" route for API
        // clients. Without Accept: application/json Laravel would try to
        // redirect and end up with a 500; the API contract is always 401 JSON.
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
