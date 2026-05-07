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
        $user = $request->user();
        if ($user) {
            $user->load('userRole.permissions');
        }
        $userData = $user ? $user->toArray() : null;
        if ($user) {
            $userData['permissions'] = $user->permissionKeys();
            $userData['is_super_admin'] = $user->isSuperAdmin();
            $userData['role_info'] = $user->userRole;
        }
        return response()->json(['data' => $userData]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        // SECURITY: Privilege fields (role, role_id, is_active, locked_until,
        // password_changed_at) are intentionally excluded — only an admin via
        // the protected /users endpoint may change them.
        $payload = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'email'      => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone'      => ['nullable', 'string', 'size:10', 'regex:/^05\d{8}$/', Rule::unique('users', 'phone')->ignore($user?->id)],
            'avatar_url' => ['nullable', 'url', 'max:1024'],
        ], [
            'phone.size'  => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
            'phone.regex' => 'رقم الجوال يجب أن يكون 10 أرقام ويبدأ بـ 05',
        ]);

        $user?->update($payload);

        return response()->json([
            'message' => 'Profile updated',
            'data'    => $user?->fresh(),
        ]);
    }
}
