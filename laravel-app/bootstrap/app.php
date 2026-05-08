<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\NoIndexHeaders::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // SECURITY: trust proxies (Cloudflare/cPanel) so that real client IPs
        // and the X-Forwarded-Proto=https header are honored.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*') === '*' ? '*' : array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES')))),
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB
        );

        $middleware->alias([
            'permission'     => \App\Http\Middleware\CheckPermission::class,
            'auth.throttle'  => \App\Http\Middleware\LoginThrottle::class,
        ]);

        // API-only backend: never redirect guests to a login page; always JSON 401.
        // Returning null here makes the Authenticate middleware skip the redirect path.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function ($request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // Defensive: if some legacy code calls route('login'), don't 500.
        $exceptions->render(function (\Symfony\Component\Routing\Exception\RouteNotFoundException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
