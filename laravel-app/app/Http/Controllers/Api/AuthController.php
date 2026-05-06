<?php

namespace App\Http\Controllers\Api;

use App\Models\LoginOtp;
use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function requestOtp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $identifier = trim(Str::lower($payload['identifier']));

        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'identifier' => 'User not found',
            ]);
        }

        // Keep local development login deterministic and easy to test.
        $code = app()->environment('local')
            ? '1111'
            : (string) random_int(1000, 9999);

        LoginOtp::query()->create([
            'identifier' => $identifier,
            'code' => $code,
            'expires_at' => now()->addMinutes(5),
        ]);

        return response()->json([
            'message' => 'Verification code sent',
            'expires_in_seconds' => 300,
            'otp_preview' => $code,
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'digits:4'],
        ]);

        $identifier = trim(Str::lower($payload['identifier']));

        // Local development shortcut: allow deterministic OTP without waiting for DB record.
        if (app()->environment('local') && $payload['code'] === '1111') {
            $user = User::query()
                ->where('email', $identifier)
                ->orWhere('phone', $identifier)
                ->first();

            if (! $user) {
                throw ValidationException::withMessages([
                    'identifier' => 'User not found',
                ]);
            }

            $token = $user->createToken('web')->plainTextToken;

            return response()->json([
                'message' => 'Authenticated with OTP',
                'token' => $token,
                'user' => $user,
            ]);
        }

        $otp = LoginOtp::query()
            ->where('identifier', $identifier)
            ->where('code', $payload['code'])
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp) {
            throw ValidationException::withMessages([
                'code' => 'Invalid or expired code',
            ]);
        }

        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'identifier' => 'User not found',
            ]);
        }

        $otp->update(['used_at' => now()]);

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'message' => 'Authenticated with OTP',
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Invalid credentials',
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
}
