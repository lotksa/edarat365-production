<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user(),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'size:10', 'regex:/^05\d{8}$/', Rule::unique('users', 'phone')->ignore($user?->id)],
            'role' => ['required', 'string', 'max:50'],
            'avatar_url' => ['nullable', 'url', 'max:1024'],
        ], [
            'phone.size'  => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
            'phone.regex' => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
        ]);

        $user?->update($payload);

        return response()->json([
            'message' => 'Profile updated',
            'data' => $user?->fresh(),
        ]);
    }
}
