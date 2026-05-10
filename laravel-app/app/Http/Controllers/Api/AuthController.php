<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoginOtp;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\OtpService;
use App\Services\TurnstileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Account lockout: 5 failed attempts → 30 minutes. */
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 30;

    public function __construct(
        private readonly OtpService $otp,
        private readonly TurnstileService $turnstile,
    ) {}

    /**
     * Public endpoint exposing the Turnstile site_key + per-page flags so the
     * SPA knows whether (and where) to render the widget. Never returns the
     * secret_key.
     */
    public function turnstileConfig(): JsonResponse
    {
        return response()->json($this->turnstile->publicConfig());
    }

    /**
     * Public endpoint returning the idle timeout window in seconds so the SPA
     * can keep its activity timer in sync with the server-side configuration.
     * Falls back to 30 minutes if the setting is missing or invalid.
     */
    public function sessionConfig(): JsonResponse
    {
        try {
            $cfg = \App\Models\Setting::getByKey('auth_settings', []);
            $minutes = (int) ($cfg['idle_timeout_minutes'] ?? 30);
        } catch (\Throwable $e) {
            $minutes = 30;
        }
        $minutes = max(1, min(1440, $minutes));
        return response()->json([
            'idle_timeout_seconds' => $minutes * 60,
            'idle_warning_seconds' => max(30, min(120, (int) ($minutes * 60 * 0.1))),
        ]);
    }

    private function normalize(string $identifier): string
    {
        return Str::lower(trim($identifier));
    }

    private function findUserByIdentifier(string $identifier): ?User
    {
        return User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();
    }

    /**
     * Throws if the user account is locked.
     */
    private function assertNotLocked(?User $user, ?string $identifier = null): void
    {
        if (!$user) return;
        if ($user->locked_until && now()->lt($user->locked_until)) {
            $minutes = (int) ceil(now()->diffInSeconds($user->locked_until) / 60);
            SecurityAuditLog::record('auth.login.blocked', 'failed', [
                'reason'  => 'account_locked',
                'minutes' => $minutes,
            ], $user, $identifier);

            throw ValidationException::withMessages([
                'identifier' => "تم قفل الحساب مؤقتاً بسبب محاولات تسجيل دخول فاشلة. حاول بعد {$minutes} دقيقة.",
            ]);
        }
    }

    private function recordFailedAttempt(?User $user, string $event, ?string $identifier = null): void
    {
        if ($user) {
            $attempts = (int) ($user->failed_login_attempts ?? 0) + 1;
            $update = [
                'failed_login_attempts' => $attempts,
                'last_failed_login_at'  => now(),
            ];
            if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
                $update['locked_until'] = now()->addMinutes(self::LOCKOUT_MINUTES);
                SecurityAuditLog::record('auth.lockout', 'success', [
                    'attempts'        => $attempts,
                    'locked_minutes'  => self::LOCKOUT_MINUTES,
                ], $user, $identifier);
            }
            DB::table('users')->where('id', $user->id)->update($update);
        }

        SecurityAuditLog::record($event, 'failed', [
            'identifier_present' => (bool) $identifier,
        ], $user, $identifier);
    }

    private function recordSuccessfulLogin(User $user, Request $request, string $event = 'auth.login.success'): void
    {
        DB::table('users')->where('id', $user->id)->update([
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'last_login_at'         => now(),
            'last_login_ip'         => $request->ip(),
        ]);

        SecurityAuditLog::record($event, 'success', [], $user, $user->email ?? $user->phone, null, $request);

        // Mirror the auth event into the human-facing activity timeline so
        // the per-user "Activity Log" tab on the User Detail page shows
        // sign-ins next to module CRUD actions.
        \App\Models\ActivityLog::create([
            'subject_type' => 'user',
            'subject_id'   => $user->id,
            'action'       => 'login',
            'description'  => 'تسجيل دخول إلى النظام',
            'performer'    => $user->name,
            'performer_id' => $user->id,
            'new_values'   => [
                'ip'      => $request->ip(),
                'event'   => $event,
                'agent'   => substr((string) $request->userAgent(), 0, 255),
            ],
        ]);
    }

    public function requestOtp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'purpose'    => ['nullable', 'in:login,password_reset'],
        ]);

        // Bot defense — runs BEFORE we touch the DB or send any SMS/email so an
        // attacker cannot use this endpoint to enumerate accounts or pump SMS.
        $this->turnstile->assertVerified($request, TurnstileService::PAGE_ADMIN_LOGIN);

        $identifier = $this->normalize($payload['identifier']);
        $purpose = $payload['purpose'] ?? OtpService::PURPOSE_LOGIN;

        $user = $this->findUserByIdentifier($identifier);
        if (!$user) {
            // Do NOT leak account existence: respond as if it succeeded.
            SecurityAuditLog::record('auth.otp.requested', 'failed', [
                'reason' => 'unknown_identifier',
            ], null, $identifier, null, $request);

            $channel = $this->otp->detectChannel($identifier);
            return response()->json([
                'message'           => 'Verification code sent',
                'channel'           => $channel,
                'purpose'           => $purpose,
                'masked_identifier' => $this->maskIdentifier($identifier, $channel),
                'expires_in_seconds' => 600,
                'delivered'         => false,
            ]);
        }

        $this->assertNotLocked($user, $identifier);

        // SECURITY: cooldown to prevent OTP spam / SMS pumping against a real
        // account. 30s between requests for the same identifier.
        $cooldownKey = 'otp_cooldown:' . sha1($identifier . '|' . $purpose);
        if (cache()->has($cooldownKey)) {
            SecurityAuditLog::record('auth.otp.requested', 'failed', [
                'reason' => 'cooldown',
            ], $user, $identifier, null, $request);
            $channel = $this->otp->detectChannel($identifier);
            return response()->json([
                'message'           => 'Verification code sent',
                'channel'           => $channel,
                'purpose'           => $purpose,
                'masked_identifier' => $this->maskIdentifier($identifier, $channel),
                'expires_in_seconds' => 600,
                'delivered'         => false,
            ]);
        }
        cache()->put($cooldownKey, 1, now()->addSeconds(30));

        $channel = $this->otp->detectChannel($identifier);
        $otp = $this->otp->createOtp($identifier, $channel, $purpose, 10);
        $delivery = $this->otp->send($otp, $user->name);

        SecurityAuditLog::record('auth.otp.requested', 'success', [
            'channel' => $channel,
            'purpose' => $purpose,
        ], $user, $identifier, null, $request);

        return response()->json([
            'message'           => 'Verification code sent',
            'channel'           => $channel,
            'purpose'           => $purpose,
            'masked_identifier' => $this->maskIdentifier($identifier, $channel),
            'expires_in_seconds' => 600,
            'delivered'         => $delivery['sent'] ?? false,
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'code'       => ['required', 'string', 'regex:/^[0-9]{4,6}$/'],
            'purpose'    => ['nullable', 'in:login,password_reset'],
        ]);

        $identifier = $this->normalize($payload['identifier']);
        $purpose = $payload['purpose'] ?? OtpService::PURPOSE_LOGIN;
        $code = $payload['code'];

        $user = $this->findUserByIdentifier($identifier);
        $this->assertNotLocked($user, $identifier);

        $otp = LoginOtp::query()
            ->where('identifier', $identifier)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (!$otp || !$user) {
            $this->recordFailedAttempt($user, 'auth.otp.failed', $identifier);
            throw ValidationException::withMessages([
                'code' => 'الرمز غير صحيح أو منتهي الصلاحية',
            ]);
        }

        // Track attempts on the OTP itself; invalidate after MAX_VERIFY_ATTEMPTS.
        if ($otp->attempts >= OtpService::MAX_VERIFY_ATTEMPTS) {
            $otp->update(['used_at' => now()]);
            SecurityAuditLog::record('auth.otp.exhausted', 'failed', [
                'attempts' => $otp->attempts,
            ], $user, $identifier, null, $request);
            throw ValidationException::withMessages([
                'code' => 'تم تجاوز الحد الأقصى لمحاولات إدخال الرمز. اطلب رمزاً جديداً.',
            ]);
        }

        if (!$this->otp->verifyCode($otp, $code)) {
            $otp->increment('attempts');
            $this->recordFailedAttempt($user, 'auth.otp.failed', $identifier);
            throw ValidationException::withMessages([
                'code' => 'الرمز غير صحيح أو منتهي الصلاحية',
            ]);
        }

        $otp->update(['used_at' => now()]);

        if ($purpose === OtpService::PURPOSE_PASSWORD_RESET) {
            SecurityAuditLog::record('auth.otp.verified', 'success', [
                'purpose' => 'password_reset',
            ], $user, $identifier, null, $request);

            return response()->json([
                'message'     => 'Verified',
                'reset_token' => $this->issueResetToken($identifier),
            ]);
        }

        $this->recordSuccessfulLogin($user, $request, 'auth.login.otp.success');
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'message' => 'Authenticated with OTP',
            'token'   => $token,
            'user'    => $user,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'identifier'  => ['required', 'string', 'max:255'],
            'reset_token' => ['required', 'string'],
            'password'    => ['required', 'confirmed', Password::min(12)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised()],
        ]);

        $this->turnstile->assertVerified($request, TurnstileService::PAGE_ADMIN_LOGIN);

        $identifier = $this->normalize($payload['identifier']);

        if (!$this->verifyResetToken($identifier, $payload['reset_token'])) {
            SecurityAuditLog::record('auth.password.reset', 'failed', [
                'reason' => 'invalid_reset_token',
            ], null, $identifier, null, $request);
            throw ValidationException::withMessages([
                'reset_token' => 'رمز إعادة التعيين غير صالح',
            ]);
        }

        $user = $this->findUserByIdentifier($identifier);
        if (!$user) {
            throw ValidationException::withMessages(['identifier' => 'User not found']);
        }

        // The 'hashed' cast on the password attribute will re-hash.
        $user->password = $payload['password'];
        $user->password_changed_at = now();
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->save();

        // Revoke all existing tokens — the user may be compromised.
        $user->tokens()->delete();

        $this->consumeResetToken($identifier);

        SecurityAuditLog::record('auth.password.reset', 'success', [], $user, $identifier, null, $request);

        return response()->json([
            'message' => 'تم تحديث كلمة المرور',
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required_without:email', 'string', 'max:255'],
            'email'      => ['required_without:identifier', 'string'],
            'password'   => ['required', 'string', 'min:1'],
        ]);

        $this->turnstile->assertVerified($request, TurnstileService::PAGE_ADMIN_LOGIN);

        $rawIdentifier = $credentials['identifier'] ?? $credentials['email'];
        $identifier = $this->normalize($rawIdentifier);

        $user = $this->findUserByIdentifier($identifier);
        $this->assertNotLocked($user, $identifier);

        // Constant-time check even if user not found (avoid user-enumeration via timing).
        $valid = false;
        if ($user) {
            $valid = Hash::check($credentials['password'], (string) $user->password);
        } else {
            // dummy compare to keep timing similar
            Hash::check($credentials['password'], '$2y$12$' . str_repeat('a', 53));
        }

        if (!$user || !$valid) {
            $this->recordFailedAttempt($user, 'auth.login.failed', $identifier);
            throw ValidationException::withMessages([
                'identifier' => 'البيانات غير صحيحة',
            ]);
        }

        if ($user->is_active === false) {
            SecurityAuditLog::record('auth.login.failed', 'failed', [
                'reason' => 'account_inactive',
            ], $user, $identifier, null, $request);
            throw ValidationException::withMessages([
                'identifier' => 'الحساب غير مفعل. تواصل مع الإدارة.',
            ]);
        }

        $this->recordSuccessfulLogin($user, $request);

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'message' => 'Authenticated',
            'token'   => $token,
            'user'    => $user,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user?->currentAccessToken()?->delete();

        if ($user) {
            SecurityAuditLog::record('auth.logout', 'success', [], $user, $user->email ?? $user->phone, null, $request);

            // Mirror logout into the human-facing activity timeline.
            \App\Models\ActivityLog::create([
                'subject_type' => 'user',
                'subject_id'   => $user->id,
                'action'       => 'logout',
                'description'  => 'تسجيل خروج من النظام',
                'performer'    => $user->name,
                'performer_id' => $user->id,
                'new_values'   => [
                    'ip'    => $request->ip(),
                    'agent' => substr((string) $request->userAgent(), 0, 255),
                ],
            ]);
        }

        return response()->json([
            'message' => 'Logged out',
        ]);
    }

    private function maskIdentifier(string $identifier, string $channel): string
    {
        if ($channel === OtpService::CHANNEL_EMAIL) {
            $parts = explode('@', $identifier);
            $name = $parts[0] ?? '';
            $domain = $parts[1] ?? '';
            $maskedName = strlen($name) <= 2 ? $name : substr($name, 0, 2) . str_repeat('*', max(0, strlen($name) - 2));
            return $maskedName . '@' . $domain;
        }

        $digits = preg_replace('/\D/', '', $identifier);
        $len = strlen($digits);
        if ($len < 4) return $digits;
        return str_repeat('*', $len - 4) . substr($digits, -4);
    }

    private function issueResetToken(string $identifier): string
    {
        $token = Str::random(64);
        cache()->put('pwreset:' . sha1($identifier), $token, now()->addMinutes(15));
        return $token;
    }

    private function verifyResetToken(string $identifier, string $token): bool
    {
        $stored = cache()->get('pwreset:' . sha1($identifier));
        return is_string($stored) && hash_equals($stored, $token);
    }

    private function consumeResetToken(string $identifier): void
    {
        cache()->forget('pwreset:' . sha1($identifier));
    }
}
