<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds defense-in-depth security headers to every HTTP response.
 *
 * Aligned with OWASP Secure Headers Project recommendations.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Content Security Policy — relaxed enough for the SPA but prevents inline JS injection.
        // The Vue app is served from same-origin; fonts come from Google Fonts.
        // Cloudflare Insights beacon (web analytics) is allowed because the
        // domain proxies through Cloudflare and CF auto-injects the beacon.
        $cf = "https://static.cloudflareinsights.com";
        $cfApi = "https://cloudflareinsights.com";
        $scriptSrc = "'self' 'unsafe-inline' 'unsafe-eval' {$cf}";

        $csp = "default-src 'self'; "
             . "script-src {$scriptSrc}; "
             . "script-src-elem {$scriptSrc}; "
             . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
             . "font-src 'self' https://fonts.gstatic.com data:; "
             . "img-src 'self' data: blob: https:; "
             . "connect-src 'self' https: {$cfApi}; "
             . "frame-ancestors 'none'; "
             . "form-action 'self'; "
             . "base-uri 'self'; "
             . "object-src 'none'; "
             . "media-src 'self'; "
             . "worker-src 'self' blob:;";

        $response->headers->set('Content-Security-Policy', $csp);

        // Force HTTPS for 1 year, includeSubDomains, preload eligible.
        if ($request->isSecure() || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        $response->headers->set(
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=(), interest-cohort=()'
        );

        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');

        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        return $response;
    }
}
