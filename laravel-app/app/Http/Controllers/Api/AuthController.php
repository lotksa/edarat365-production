<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoginOtp;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

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

    public function requestOtp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'in:login,password_reset'],
        ]);

        $identifier = $this->normalize($payload['identifier']);
        $purpose = $payload['purpose'] ?? OtpService::PURPOSE_LOGIN;

        $user = $this->findUserByIdentifier($identifier);
        if (! $user) {
            throw ValidationException::withMessages([
                'identifier' => 'لم يتم العثور على هذا الحساب',
            ]);
        }

        $channel = $this->otp->detectChannel($identifier);
        $otp = $this->otp->createOtp($identifier, $channel, $purpose, 10);
        $delivery = $this->otp->send($otp, $user->name);

        $masked = $this->maskIdentifier($identifier, $channel);

        return response()->json([
            'message' => 'Verification code sent',
            'channel' => $channel,
            'purpose' => $purpose,
            'masked_identifier' => $masked,
            'expires_in_seconds' => 600,
            'otp_preview' => $delivery['preview'] ?? null,
            'delivered' => $delivery['sent'] ?? false,
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'regex:/^[0-9]{4,6}$/'],
            'purpose' => ['nullable', 'in:login,password_reset'],
        ]);

        $identifier = $this->normalize($payload['identifier']);
        $purpose = $payload['purpose'] ?? OtpService::PURPOSE_LOGIN;

        if (app()->environment('local') && in_array($payload['code'], ['1111', '111111'], true)) {
            $user = $this->findUserByIdentifier($identifier);
            if (! $user) {
                throw ValidationException::withMessages(['identifier' => 'User not found']);
            }

            if ($purpose === OtpService::PURPOSE_PASSWORD_RESET) {
                return response()->json([
                    'message' => 'Verified',
                    'reset_token' => $this->issueResetToken($identifier),
                ]);
            }

            $token = $user->createToken('web')->plainTextToken;
            return response()->json([
                'message' => 'Authenticated',
                'token' => $token,
                'user' => $user,
            ]);
        }

        $otp = LoginOtp::query()
            ->where('identifier', $identifier)
            ->where('purpose', $purpose)
            ->where('code', $payload['code'])
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp) {
            throw ValidationException::withMessages([
                'code' => 'الرمز غير صحيح أو منتهي الصلاحية',
            ]);
        }

        $user = $this->findUserByIdentifier($identifier);
        if (! $user) {
            throw ValidationException::withMessages(['identifier' => 'User not found']);
        }

        $otp->update(['used_at' => now()]);

        if ($purpose === OtpService::PURPOSE_PASSWORD_RESET) {
            return response()->json([
                'message' => 'Verified',
                'reset_token' => $this->issueResetToken($identifier),
            ]);
        }

        $token = $user->createToken('web')->plainTextToken;
        return response()->json([
            'message' => 'Authenticated with OTP',
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'reset_token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $identifier = $this->normalize($payload['identifier']);

        if (! $this->verifyResetToken($identifier, $payload['reset_token'])) {
            throw ValidationException::withMessages([
                'reset_token' => 'رمز إعادة التعيين غير صالح',
            ]);
        }

        $user = $this->findUserByIdentifier($identifier);
        if (! $user) {
            throw ValidationException::withMessages(['identifier' => 'User not found']);
        }

        $user->password = Hash::make($payload['password']);
        $user->save();

        $this->consumeResetToken($identifier);

        return response()->json([
            'message' => 'تم تحديث كلمة المرور',
            'user' => $user,
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required_without:email', 'string', 'max:255'],
            'email' => ['required_without:identifier', 'string'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $rawIdentifier = $credentials['identifier'] ?? $credentials['email'];
        $identifier = $this->normalize($rawIdentifier);

        $user = $this->findUserByIdentifier($identifier);
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => 'البيانات غير صحيحة',
            ]);
        }

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'message' => 'Authenticated',
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

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
