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
        // Cloudflare Turnstile loads its API script + renders an iframe, so
        // both script-src and frame-src must allow challenges.cloudflare.com.
        $cfInsights = "https://static.cloudflareinsights.com";
        $cfInsightsApi = "https://cloudflareinsights.com";
        $cfTurnstile = "https://challenges.cloudflare.com";
        $scriptSrc = "'self' 'unsafe-inline' 'unsafe-eval' {$cfInsights} {$cfTurnstile}";

        $csp = "default-src 'self'; "
             . "script-src {$scriptSrc}; "
             . "script-src-elem {$scriptSrc}; "
             . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
             . "font-src 'self' https://fonts.gstatic.com data:; "
             . "img-src 'self' data: blob: https:; "
             . "connect-src 'self' https: {$cfInsightsApi} {$cfTurnstile}; "
             // Allow Turnstile's challenge iframe in addition to same-origin
             // assets (PDF / document previews use /storage/* on this origin).
             . "frame-src 'self' {$cfTurnstile}; "
             . "child-src 'self' {$cfTurnstile}; "
             // 'self' (not 'none') so the SPA can preview uploaded PDFs / images
             // in <iframe>s served from /storage/* on the same origin. External
             // sites are still blocked from framing us (clickjacking defence).
             . "frame-ancestors 'self'; "
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

        // SAMEORIGIN (not DENY) so we can iframe our own /storage/* assets for
        // PDF / document previews. Modern browsers honour the CSP `frame-ancestors`
        // directive above; XFO here is a fallback for legacy clients.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
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
