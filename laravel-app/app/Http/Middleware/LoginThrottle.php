<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strict rate limiter for authentication endpoints (login, OTP, password reset).
 *
 * Layered protection:
 *   1) IP-based throttle (per-route)
 *   2) Identifier-based throttle (per email/phone)
 *
 * Use as `auth.throttle:LIMIT,DECAY,KEY` where:
 *   LIMIT  = max attempts (default 5)
 *   DECAY  = window in minutes (default 1)
 *   KEY    = unique scope (e.g. login, otp_request, otp_verify, reset)
 */
class LoginThrottle
{
    public function handle(Request $request, Closure $next, string $limit = '5', string $decay = '1', string $key = 'auth'): Response
    {
        $maxAttempts = (int) $limit;
        $decayMinutes = (int) $decay;

        $ip = $request->ip();
        $identifier = strtolower(trim((string) (
            $request->input('identifier')
                ?? $request->input('email')
                ?? $request->input('phone')
                ?? ''
        )));

        $ipKey = "throttle:{$key}:ip:" . sha1($ip);
        $idKey = "throttle:{$key}:id:" . sha1($identifier);

        foreach ([$ipKey, $idKey] as $bucket) {
            if ($identifier === '' && $bucket === $idKey) continue;

            if (RateLimiter::tooManyAttempts($bucket, $maxAttempts)) {
                $seconds = RateLimiter::availableIn($bucket);

                Log::warning('Auth rate limit hit', [
                    'key'        => $key,
                    'ip'         => $ip,
                    'identifier' => $identifier ? Str::mask($identifier, '*', 2, max(0, strlen($identifier) - 4)) : null,
                    'retry_in'   => $seconds,
                ]);

                return response()->json([
                    'message' => 'تجاوزت الحد المسموح من المحاولات. حاول مرة أخرى بعد ' . max(1, (int) ceil($seconds / 60)) . ' دقيقة.',
                    'retry_after_seconds' => $seconds,
                ], 429)->header('Retry-After', (string) $seconds);
            }
        }

        RateLimiter::hit($ipKey, $decayMinutes * 60);
        if ($identifier !== '') {
            RateLimiter::hit($idKey, $decayMinutes * 60);
        }

        return $next($request);
    }
}
