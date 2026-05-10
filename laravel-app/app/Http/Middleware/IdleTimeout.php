<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side idle session timeout for Sanctum tokens.
 *
 * The frontend has its own activity timer, but we MUST also enforce the
 * timeout on the server so that stolen tokens or browser-bypass attacks
 * cannot keep a session alive past the configured idle window.
 *
 * Implementation:
 *   - Sanctum updates personal_access_tokens.last_used_at automatically
 *     when the token is resolved by the guard. By the time we run, it is
 *     already "now()" — so we can't use the column itself for idle checks.
 *   - Instead we maintain our own per-token last_seen timestamp in the
 *     application cache (default driver: database, file or redis — all OK).
 *   - On every authenticated request:
 *       1. If we have a previous timestamp AND it is older than the
 *          configured idle window, the token is REVOKED and 401 is returned.
 *       2. Otherwise the timestamp is refreshed to now().
 *
 * Setting key: `auth_settings.idle_timeout_minutes` (default 30, clamp 1..1440).
 */
class IdleTimeout
{
    private const DEFAULT_MINUTES = 30;
    private const MIN_MINUTES = 1;
    private const MAX_MINUTES = 1440; // 24h

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // No token / non-Sanctum auth — let it pass.
        if (!$token || !isset($token->id)) {
            return $next($request);
        }

        $idleMinutes = $this->idleMinutes();
        $idleSeconds = $idleMinutes * 60;
        $cacheKey = 'auth:idle:' . $token->id;

        $previous = Cache::get($cacheKey);
        $now = time();

        if ($previous !== null) {
            $elapsed = $now - (int) $previous;
            if ($elapsed > $idleSeconds) {
                // Idle timeout exceeded → revoke token, drop the cache entry.
                try { $token->delete(); } catch (\Throwable $e) { /* noop */ }
                Cache::forget($cacheKey);
                return response()->json([
                    'message' => 'انتهت الجلسة بسبب عدم النشاط. يرجى تسجيل الدخول مرة أخرى.',
                    'reason'  => 'idle_timeout',
                ], 401);
            }
        }

        // Refresh activity timestamp. TTL = idle window + 5min slack so the key
        // self-expires shortly after the user stops being active (cleanup).
        Cache::put($cacheKey, $now, now()->addSeconds($idleSeconds + 300));

        $response = $next($request);

        // Tell the SPA the configured idle window so it can sync its own timer.
        $response->headers->set('X-Session-Idle-Timeout', (string) $idleSeconds);

        return $response;
    }

    private function idleMinutes(): int
    {
        try {
            $cfg = Setting::getByKey('auth_settings', []);
            $val = (int) ($cfg['idle_timeout_minutes'] ?? self::DEFAULT_MINUTES);
        } catch (\Throwable $e) {
            $val = self::DEFAULT_MINUTES;
        }
        return max(self::MIN_MINUTES, min(self::MAX_MINUTES, $val));
    }
}
